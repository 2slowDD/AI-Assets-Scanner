<?php
namespace CUScanner\Cdn;

defined( 'ABSPATH' ) || exit;

final class Detector {
    private const CACHE_KEY = 'cu_scanner_cdn_detected';
    private const TTL_HIT   = 43200; // 12h
    private const TTL_MISS  = 1800;  // 30m (re-probe sooner on unknown)

    private Registry $registry;

    public function __construct( ?Registry $registry = null ) {
        $this->registry = $registry ?? self::default_registry();
    }

    public static function default_registry(): Registry {
        return new Registry( [
            new CloudflareAdapter(),
            new GenericAdapter( 'bunnycdn', fn( array $h ) => isset( $h['server'] ) && stripos( $h['server'], 'bunnycdn' ) !== false ),
            new GenericAdapter( 'fastly',   fn( array $h ) => ( isset( $h['x-served-by'] ) && stripos( $h['x-served-by'], 'cache' ) !== false ) || ( isset( $h['via'] ) && stripos( $h['via'], 'varnish' ) !== false ) ),
            new GenericAdapter( 'akamai',   fn( array $h ) => isset( $h['x-akamai-transformed'] ) || ( isset( $h['server'] ) && stripos( $h['server'], 'akamai' ) !== false ) ),
            new GenericAdapter( 'sucuri',   fn( array $h ) => isset( $h['x-sucuri-id'] ) || isset( $h['x-sucuri-cache'] ) ),
        ] );
    }

    /**
     * Detect the CDN serving this WordPress site.
     *
     * Priority order:
     * 1. Inbound-request headers ($_SERVER) — checked FIRST. The current browser
     *    request traversed the CDN (browser → CDN → origin), so CDN fingerprint
     *    headers (e.g. HTTP_CF_RAY, HTTP_CF_CONNECTING_IP) are visible in $_SERVER.
     *    This path is free (no HTTP), is authoritative, and self-heals stale cached
     *    misses (e.g. from split-horizon loopback self-sniffs on Hostinger).
     * 2. Transient cache — avoids repeated self-sniff HTTP calls.
     * 3. Server-side self-sniff GET to home_url('/') — fallback for the first hit
     *    after cache expiry. Fail-quiet on WP_Error.
     *
     * SSRF note: the self-sniff target is always home_url('/') — a WordPress core
     * function returning this site's own URL. It is never user-supplied input.
     *
     * @return string|null Detected CDN adapter name, or null when unknown/error.
     */
    public function detect( bool $force = false ): ?string {
        // 1. Inbound-request detection: checked before transient and self-sniff.
        //    Overrides any stale cached miss on split-horizon hosts (e.g. Hostinger + CF).
        $from_request = $this->detect_from_request();
        if ( null !== $from_request ) {
            set_transient( self::CACHE_KEY, $from_request, self::TTL_HIT );
            return $from_request;
        }

        // 2. Transient cache.
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( $cached !== false ) {
                return $cached === '' ? null : $cached;
            }
        }

        // 3. Self-sniff fallback.
        $resp = wp_remote_get( home_url( '/' ), [ 'timeout' => 5 ] );

        if ( is_wp_error( $resp ) ) {
            set_transient( self::CACHE_KEY, '', self::TTL_MISS );
            return null;
        }

        $headers = [];
        foreach ( (array) wp_remote_retrieve_headers( $resp ) as $k => $v ) {
            $headers[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ',', $v ) : (string) $v;
        }

        $adapter = $this->registry->detect( $headers );
        $name    = $adapter ? $adapter->name() : '';
        set_transient( self::CACHE_KEY, $name, $name === '' ? self::TTL_MISS : self::TTL_HIT );
        return $name === '' ? null : $name;
    }

    /**
     * Detect CDN from the current inbound request's $_SERVER headers.
     *
     * Normalises HTTP_* keys (strip prefix, lowercase, underscores → dashes) and
     * runs them through the adapter registry. Values are cast to string for
     * header-map shape compatibility; they are NEVER echoed or output anywhere —
     * the return value is always an adapter name() from our own registry or null.
     *
     * @return string|null Matched adapter name, or null if no CDN fingerprint found.
     */
    private function detect_from_request(): ?string {
        $headers = [];
        foreach ( $_SERVER as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values used only for header-name matching via adapter detect(); never echoed or output; return value is always adapter name() from our registry.
            if ( strncmp( $key, 'HTTP_', 5 ) !== 0 ) {
                continue;
            }
            $header_name             = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );
            $headers[ $header_name ] = (string) $value;
        }

        if ( empty( $headers ) ) {
            return null;
        }

        $adapter = $this->registry->detect( $headers );
        return $adapter ? $adapter->name() : null;
    }
}

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
     * Performs a single self-sniff GET to home_url('/') with a 5-second timeout
     * so a slow or down origin never blocks admin render. Result is cached in a
     * transient (12h on hit, 30m on miss/error) to avoid repeated HTTP calls.
     * Fail-quiet: a WP_Error response sets the miss transient and returns null.
     *
     * SSRF note: the request target is always home_url('/') — a WordPress core
     * function returning this site's own URL. It is never user-supplied input.
     *
     * @return string|null Detected CDN adapter name, or null when unknown/error.
     */
    public function detect( bool $force = false ): ?string {
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( $cached !== false ) {
                return $cached === '' ? null : $cached;
            }
        }

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
}

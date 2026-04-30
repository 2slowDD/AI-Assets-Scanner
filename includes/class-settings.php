<?php
namespace CUScanner;

defined( 'ABSPATH' ) || exit;

class Settings {
    /**
     * Allowed hostnames for Railway worker URL. SaaS auth response field
     * `railway_url` is validated against this list before storage and again
     * before each outbound HTTP call. Hardcoded — not filterable — because
     * this plugin runs on untrusted client sites and the allowlist is the
     * primary defence against a compromised SaaS pointing the plugin at an
     * attacker-controlled host (which would receive each customer's
     * job_token Bearer credential).
     *
     * Wildcard `*.up.railway.app` is intentionally NOT used: that domain is
     * shared infrastructure where any Railway user can register a
     * subdomain.
     */
    private const ALLOWED_RAILWAY_HOSTS = array(
        'cu-scanner-railway-production.up.railway.app',
    );

    public function get_api_key(): string {
        return (string) get_option( 'cu_scanner_api_key', '' );
    }

    public function set_api_key( string $key ): void {
        update_option( 'cu_scanner_api_key', $key );
    }

    public function get_railway_url(): string {
        return (string) get_option( 'cu_scanner_railway_url', '' );
    }

    /**
     * @throws \RuntimeException if $url is not on the allowlist or not HTTPS.
     */
    public function set_railway_url( string $url ): void {
        if ( ! self::is_safe_railway_url( $url ) ) {
            throw new \RuntimeException( 'Refused to store Railway URL: must be HTTPS and on the host allowlist.' );
        }
        update_option( 'cu_scanner_railway_url', $url );
    }

    /**
     * Validates a Railway URL: scheme must be https, host must match the
     * allowlist exactly, no userinfo, and no non-default port. Intentionally
     * cheap (no DNS) — safe to call on the hot path before every outbound
     * request without measurable cost.
     */
    public static function is_safe_railway_url( string $url ): bool {
        if ( '' === $url ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return false;
        }
        if ( ( $parts['scheme'] ?? '' ) !== 'https' ) {
            return false;
        }
        // Reject userinfo (e.g. https://attacker@host/...) — would be sent as
        // Basic auth on the request, conflicting with our Bearer header.
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return false;
        }
        // Reject any explicit non-default port. Production Railway is on 443.
        if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
            return false;
        }
        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        if ( '' === $host ) {
            return false;
        }
        return in_array( $host, self::ALLOWED_RAILWAY_HOSTS, true );
    }

    public function set_http_auth( string $username, string $password ): void {
        $payload   = json_encode( [ 'username' => $username, 'password' => $password ] );
        $key       = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
        $iv        = random_bytes( 16 );
        $encrypted = openssl_encrypt( $payload, 'AES-256-CBC', $key, 0, $iv );
        update_option( 'cu_scanner_http_auth', base64_encode( $iv ) . ':' . $encrypted );
    }

    public function get_http_auth(): ?array {
        $stored = (string) get_option( 'cu_scanner_http_auth', '' );
        if ( ! $stored ) return null;
        $parts = explode( ':', $stored, 2 );
        if ( count( $parts ) !== 2 ) return null; // corrupted option — fail closed
        [ $iv_b64, $encrypted ] = $parts;
        $key  = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
        $iv   = base64_decode( $iv_b64 );
        $json = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );
        return $json ? json_decode( $json, true ) : null;
    }

    public function clear_http_auth(): void {
        delete_option( 'cu_scanner_http_auth' );
    }

    public function get_scanner_secret(): string {
        $secret = (string) get_option( 'cu_scanner_secret', '' );
        if ( ! $secret ) {
            // 128 bits from CSPRNG (random_bytes) — stronger than wp_generate_uuid4()
            // which is mt_rand-derived. Stored values from the previous UUID4
            // generator continue to work; only first-run generation changes.
            $secret = bin2hex( random_bytes( 16 ) );
            update_option( 'cu_scanner_secret', $secret, false );
        }
        return $secret;
    }
}

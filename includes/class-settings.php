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

    public function get_paid_key_claim_token(): string {
        $token = (string) get_option( 'cu_scanner_paid_key_claim_token', '' );
        if ( preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return $token;
        }

        $token = bin2hex( random_bytes( 32 ) );
        update_option( 'cu_scanner_paid_key_claim_token', $token, false );
        return $token;
    }

    public function is_free_key( string $key ): bool {
        return (bool) preg_match( '/^cusk_Freekey_[1-9][0-9]*$/', $key );
    }

    public function is_pending_free_key( string $key ): bool {
        return 'cusk_Freekey_?' === $key;
    }

    public function has_pending_free_key(): bool {
        return $this->is_pending_free_key( $this->get_api_key() );
    }

    public function set_pending_free_key(): void {
        $this->set_api_key( 'cusk_Freekey_?' );
        update_option( 'cu_scanner_free_key_pending', '1', false );
    }

    public function clear_pending_free_key(): void {
        delete_option( 'cu_scanner_free_key_pending' );
    }

    public function get_buy_credits_url( ?string $api_key = null ): string {
        $api_key = $api_key ?? $this->get_api_key();
        $base    = ( defined( 'CU_SCANNER_WPSERVICE_BASE' ) ? CU_SCANNER_WPSERVICE_BASE : 'https://wpservice.pro' )
            . '/our-products/ai-assets-scanner/';

        if ( ! $this->is_free_key( $api_key ) ) {
            return $base . '#cu-pricing-inner';
        }

        $query = http_build_query( [
            'cu_free_key'    => $api_key,
            'cu_domain'      => DomainNormalizer::normalize_url( get_home_url() ),
            'cu_claim_token' => $this->get_paid_key_claim_token(),
        ] );

        return $base . '?' . $query . '#cu-pricing-inner';
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

    /**
     * Storage prefix for the v2 (sodium_crypto_secretbox / XSalsa20-Poly1305 AEAD)
     * HTTP-auth blob format. Anything stored without this prefix is the legacy
     * AES-256-CBC blob from <= 1.2.3. On hosts where libsodium is loaded, legacy
     * blobs get transparently re-encrypted to v2 the first time get_http_auth()
     * reads them. On hosts without sodium, both encryption and migration fall
     * back to the legacy AES-256-CBC primitive — same behaviour as 1.2.3, no
     * regression — but the AEAD upgrade is missed.
     */
    private const HTTP_AUTH_V2_PREFIX = 'v2:';

    public function set_http_auth( string $username, string $password ): void {
        update_option(
            'cu_scanner_http_auth',
            self::encrypt_http_auth( array( 'username' => $username, 'password' => $password ) )
        );
    }

    public function get_http_auth(): ?array {
        $stored = (string) get_option( 'cu_scanner_http_auth', '' );
        if ( '' === $stored ) return null;

        // v2 AEAD format — decrypt with sodium. If sodium isn't available on
        // this host (rare but possible — e.g. wp-config moved between hosts),
        // decrypt_http_auth_v2 returns null rather than fataling.
        if ( str_starts_with( $stored, self::HTTP_AUTH_V2_PREFIX ) ) {
            return self::decrypt_http_auth_v2( $stored );
        }

        // Legacy AES-256-CBC blob (<= 1.2.3). Decrypt, then if sodium is
        // available, transparently re-encrypt with the v2 AEAD format so
        // subsequent reads use the authenticated path. Migration is
        // best-effort: if the re-encrypt write fails or sodium is missing,
        // the call still returns the decoded value.
        $decoded = self::decrypt_http_auth_legacy( $stored );
        if ( null !== $decoded && self::sodium_available() ) {
            update_option( 'cu_scanner_http_auth', self::encrypt_http_auth_v2( $decoded ) );
        }
        return $decoded;
    }

    public function clear_http_auth(): void {
        delete_option( 'cu_scanner_http_auth' );
    }

    /**
     * Pick the strongest encryption primitive available on this host.
     * Sodium (XSalsa20-Poly1305 AEAD) preferred; falls back to AES-256-CBC
     * if libsodium is missing.
     */
    private static function encrypt_http_auth( array $payload ): string {
        if ( self::sodium_available() ) {
            return self::encrypt_http_auth_v2( $payload );
        }
        return self::encrypt_http_auth_legacy( $payload );
    }

    /**
     * v2 encrypt: XSalsa20-Poly1305 AEAD via libsodium. Authenticated
     * encryption — ciphertext tampering yields decrypt failure, not silent
     * plaintext manipulation.
     *
     * Output format: "v2:" + base64(nonce) + ":" + base64(ciphertext_with_tag)
     *
     * Caller MUST gate on self::sodium_available() — this method assumes it.
     */
    private static function encrypt_http_auth_v2( array $payload ): string {
        $key   = self::derive_http_auth_key_v2();
        $nonce = random_bytes( \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $msg   = (string) wp_json_encode( $payload );
        $ct    = sodium_crypto_secretbox( $msg, $nonce, $key );
        return self::HTTP_AUTH_V2_PREFIX . base64_encode( $nonce ) . ':' . base64_encode( $ct );
    }

    private static function decrypt_http_auth_v2( string $stored ): ?array {
        if ( ! self::sodium_available() ) return null;
        $body  = substr( $stored, strlen( self::HTTP_AUTH_V2_PREFIX ) );
        $parts = explode( ':', $body, 2 );
        if ( 2 !== count( $parts ) ) return null;
        [ $nonce_b64, $ct_b64 ] = $parts;
        $nonce = base64_decode( $nonce_b64, true );
        $ct    = base64_decode( $ct_b64, true );
        if ( false === $nonce || false === $ct ) return null;
        if ( \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== strlen( $nonce ) ) return null;
        $key = self::derive_http_auth_key_v2();
        $msg = sodium_crypto_secretbox_open( $ct, $nonce, $key );
        if ( false === $msg ) return null; // tampered or wrong key
        $decoded = json_decode( $msg, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Encrypt with the legacy AES-256-CBC primitive. Used as a fallback when
     * libsodium is unavailable — same wire format as 1.2.3 so existing tooling
     * still works. Writers prefer encrypt_http_auth_v2() when possible.
     */
    private static function encrypt_http_auth_legacy( array $payload ): string {
        $msg       = (string) wp_json_encode( $payload );
        $key       = self::derive_http_auth_key_legacy();
        $iv        = random_bytes( 16 );
        $encrypted = openssl_encrypt( $msg, 'AES-256-CBC', $key, 0, $iv );
        return base64_encode( $iv ) . ':' . $encrypted;
    }

    /**
     * Decrypt a legacy AES-256-CBC blob written by Settings <= 1.2.3 (or by
     * encrypt_http_auth_legacy() above on hosts without libsodium).
     * Format: base64(iv:16-bytes) + ":" + openssl-aes-256-cbc-base64-ciphertext.
     */
    private static function decrypt_http_auth_legacy( string $stored ): ?array {
        $parts = explode( ':', $stored, 2 );
        if ( 2 !== count( $parts ) ) return null;
        [ $iv_b64, $encrypted ] = $parts;
        $iv = base64_decode( $iv_b64, true );
        if ( false === $iv || 16 !== strlen( $iv ) ) return null;
        $key  = self::derive_http_auth_key_legacy();
        $json = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );
        if ( false === $json ) return null;
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    private static function derive_http_auth_key_v2(): string {
        // 32 raw bytes for sodium_crypto_secretbox.
        return hash( 'sha256', wp_salt( 'auth' ), true );
    }

    private static function derive_http_auth_key_legacy(): string {
        // Legacy <= 1.2.3 derivation: 32 hex chars (== 32 ASCII bytes) from
        // the start of the hex SHA-256 of wp_salt('auth'). Used to decrypt
        // blobs written before the v2 migration AND on hosts without sodium.
        return substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
    }

    private static function sodium_available(): bool {
        return function_exists( 'sodium_crypto_secretbox' )
            && function_exists( 'sodium_crypto_secretbox_open' )
            && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
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

<?php
namespace CUScanner\Scanner;

if ( ! defined( 'ABSPATH' ) ) exit;

class BypassManager {
    private const TOKEN_LIST_OPTION = 'cu_scanner_active_tokens';
    private const TOKEN_TTL         = 3600; // 1 hour

    public function create_token(): string {
        // 128 bits from CSPRNG (random_bytes) — stronger than wp_generate_uuid4()
        // which is mt_rand-derived. House precedent: class-settings.php get_scanner_secret().
        // random_bytes() throws on insufficient entropy; let it propagate (fail-loud) —
        // a scan token minted from a weak/failed source must not silently succeed.
        $token            = bin2hex( random_bytes( 16 ) );
        $tokens           = $this->load_tokens();
        $tokens[ $token ] = time() + self::TOKEN_TTL;
        $this->store_tokens( $tokens );
        return $token;
    }

    public function delete_all_tokens(): void {
        $this->store_tokens( [] );
    }

    public function is_valid_token( string $token ): bool {
        $tokens = $this->load_tokens();
        if ( ! isset( $tokens[ $token ] ) ) {
            return false;
        }
        $expires = (int) $tokens[ $token ];
        if ( $expires <= time() ) {
            // Prune expired entry; persist GC'd map.
            unset( $tokens[ $token ] );
            $this->store_tokens( $this->prune_expired( $tokens ) );
            return false;
        }
        return true;
    }

    /**
     * Build a scan URL with bypass token + all applicable bypass params.
     *
     * @param string   $url    Raw page URL
     * @param string   $token  Scan bypass token
     * @param string[] $params Extra bypass params (e.g. ['nowpcu' => '', 'nowprocket' => ''])
     */
    public function build_url( string $url, string $token, array $params = [] ): string {
        $all_params = array_merge( [ 'cu_scan_token' => $token ], $params );
        return add_query_arg( $all_params, $url );
    }

    /**
     * Hook: wp_loaded — validate scan token and grant access to password-protected pages.
     * Register in Plugin::init() on the frontend only.
     */
    public function handle_wp_loaded(): void {
        if ( empty( $_GET['cu_scan_token'] ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-based auth used intentionally; nonces are incompatible with headless browser scanner requests.
        $token = sanitize_text_field( wp_unslash( $_GET['cu_scan_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
        if ( ! $this->is_valid_token( $token ) ) return;
        add_filter( 'post_password_required', '__return_false' );
    }

    /**
     * Load the active-tokens map from durable option storage.
     *
     * Legacy storage was a flat list of token strings (pre Phase-3 P0 fix);
     * those entries have numeric keys — they are silently dropped so the
     * legacy tokens are treated as expired.
     *
     * @return array<string, int>  token => expires_at_unix_ts
     */
    private function load_tokens(): array {
        $stored = get_option( self::TOKEN_LIST_OPTION, [] );
        if ( ! is_array( $stored ) ) {
            return [];
        }
        $out = [];
        foreach ( $stored as $key => $value ) {
            // Map shape (post-migration): string token => int expires_at.
            if ( is_string( $key ) && is_int( $value ) ) {
                $out[ $key ] = $value;
            }
            // Legacy flat-list shape (numeric key, string value) is dropped.
        }
        return $out;
    }

    private function store_tokens( array $tokens ): void {
        update_option( self::TOKEN_LIST_OPTION, $tokens, false );
    }

    /**
     * @param array<string, int> $tokens
     * @return array<string, int>
     */
    private function prune_expired( array $tokens ): array {
        $now = time();
        return array_filter( $tokens, static fn( $expires ) => (int) $expires > $now );
    }
}

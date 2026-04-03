<?php
namespace CUScanner\Scanner;

if ( ! defined( 'ABSPATH' ) ) exit;

class BypassManager {
    private const TRANSIENT_PREFIX  = 'cu_scan_token_';
    private const TOKEN_LIST_OPTION = 'cu_scanner_active_tokens';
    private const TOKEN_TTL         = 3600; // 1 hour

    public function create_token(): string {
        $token    = wp_generate_uuid4();
        set_transient( self::TRANSIENT_PREFIX . $token, 1, self::TOKEN_TTL );
        $tokens   = get_option( self::TOKEN_LIST_OPTION, [] );
        $tokens[] = $token;
        update_option( self::TOKEN_LIST_OPTION, $tokens );
        return $token;
    }

    public function delete_all_tokens(): void {
        $tokens = get_option( self::TOKEN_LIST_OPTION, [] );
        foreach ( $tokens as $token ) {
            delete_transient( self::TRANSIENT_PREFIX . $token );
        }
        update_option( self::TOKEN_LIST_OPTION, [] );
    }

    public function is_valid_token( string $token ): bool {
        return (bool) get_transient( self::TRANSIENT_PREFIX . $token );
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
        if ( empty( $_GET['cu_scan_token'] ) ) return;
        $token = sanitize_text_field( wp_unslash( $_GET['cu_scan_token'] ) );
        if ( ! $this->is_valid_token( $token ) ) return;
        add_filter( 'post_password_required', '__return_false' );
    }
}

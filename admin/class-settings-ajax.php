<?php
namespace CUScanner\Admin;

use CUScanner\Settings;
use CUScanner\Api\WpserviceClient;

class SettingsAjax {
    public function register(): void {
        add_action( 'wp_ajax_cu_scanner_save_settings', [ $this, 'save_settings' ] );
        add_action( 'wp_ajax_cu_scanner_fetch_balance', [ $this, 'fetch_balance' ] );
    }

    public function save_settings(): void {
        check_ajax_referer( 'cu_scanner_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $settings = new Settings();
        $api_key  = sanitize_text_field( $_POST['api_key'] ?? '' );
        $settings->set_api_key( $api_key );

        $http_user = sanitize_text_field( $_POST['http_user'] ?? '' );
        $http_pass = sanitize_text_field( $_POST['http_pass'] ?? '' );
        if ( $http_user && $http_pass ) {
            $settings->set_http_auth( $http_user, $http_pass );
        } elseif ( isset( $_POST['clear_http_auth'] ) ) {
            $settings->clear_http_auth();
        }

        // Validate API key and cache Railway URL
        try {
            $client = new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $api_key );
            $auth   = $client->authenticate();
            $settings->set_railway_url( $auth['railway_url'] );
            wp_send_json_success( [ 'credits' => $auth['balance'], 'railway_url' => $auth['railway_url'] ] );
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    public function fetch_balance(): void {
        check_ajax_referer( 'cu_scanner_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $settings = new Settings();
        try {
            $client  = new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $settings->get_api_key() );
            $balance = $client->get_credits();
            wp_send_json_success( $balance );
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }
}

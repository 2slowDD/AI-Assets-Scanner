<?php
namespace CUScanner;

use CUScanner\Api\WpserviceClient;

defined( 'ABSPATH' ) || exit;

class FreeKeyBootstrap {

    /** @var callable|null */
    private $client_factory;

    public function __construct(
        private ?Settings $settings = null,
        ?callable $client_factory = null
    ) {
        $this->settings       = $settings ?? new Settings();
        $this->client_factory = $client_factory;
    }

    public function run(): void {
        $current = $this->settings->get_api_key();
        if ( '' !== $current && ! $this->settings->is_free_key( $current ) && ! $this->settings->is_pending_free_key( $current ) ) {
            return;
        }

        try {
            $client  = $this->make_client( $current );
            $result  = $client->register_free_key( $current );
            $api_key = (string) ( $result['api_key'] ?? '' );
            if ( function_exists( 'sanitize_text_field' ) ) {
                $sanitized = sanitize_text_field( $api_key );
                $api_key   = is_string( $sanitized ) ? $sanitized : $api_key;
            }
            if ( $this->settings->is_free_key( $api_key ) ) {
                $this->settings->set_api_key( $api_key );
                $this->settings->clear_pending_free_key();
            }
        } catch ( \RuntimeException $e ) {
            if ( '' === $current ) {
                $this->settings->set_pending_free_key();
            }
            self::schedule_retry();
        }
    }

    public static function schedule_retry(): void {
        if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( 'cu_scanner_free_key_retry' ) ) {
            wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'cu_scanner_free_key_retry' );
        }
    }

    private function make_client( string $current_key ): object {
        if ( $this->client_factory ) {
            return ( $this->client_factory )( $current_key );
        }
        return new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $current_key );
    }
}


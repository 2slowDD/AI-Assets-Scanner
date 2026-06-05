<?php
namespace CUScanner\Api;

use CUScanner\DomainNormalizer;

defined( 'ABSPATH' ) || exit;

class WpserviceClient {
    public function __construct(
        private readonly string $base_url,
        private readonly string $api_key
    ) {}

    public function authenticate(): array {
        return $this->post( '/cu-scanner/v1/auth', [ 'domain' => $this->domain() ] );
    }

    public function get_credits(): array {
        return $this->get( '/cu-scanner/v1/credits', [ 'domain' => $this->domain() ] );
    }

    public function register_free_key( string $current_api_key = '' ): array {
        return $this->post( '/cu-scanner/v1/free-key/register', [
            'domain'          => $this->domain(),
            'current_api_key' => $current_api_key,
            'plugin_version'  => defined( 'CU_SCANNER_VERSION' ) ? CU_SCANNER_VERSION : '',
        ] );
    }

    public function claim_paid_key( string $current_api_key, string $claim_token ): array {
        return $this->post( '/cu-scanner/v1/free-key/claim-paid', [
            'domain'          => $this->domain(),
            'current_api_key' => $current_api_key,
            'claim_token'     => $claim_token,
            'plugin_version'  => defined( 'CU_SCANNER_VERSION' ) ? CU_SCANNER_VERSION : '',
        ] );
    }

    /**
     * @throws \RuntimeException with message 'Insufficient credits' on 402
     */
    public function reserve_job( int $page_count, int $extra_time_count = 0 ): array {
        return $this->post( '/cu-scanner/v1/jobs/reserve', [
            'page_count'       => $page_count,
            'extra_time_count' => max( 0, $extra_time_count ),
            'domain'           => $this->domain(),
        ] );
    }

    public function release_credits( string $job_token ): void {
        $this->post( '/cu-scanner/v1/credits/release', [
            'job_token' => $job_token,
            'domain'    => $this->domain(),
        ] );
    }

    /**
     * POST batch of audit events to SaaS /cu-scanner/v1/events endpoint.
     *
     * @param string $scan_id  Scan ID (hash, 12-16 chars).
     * @param array  $events   Each: { name: string, category: string, fields: array }.
     * @return array  { accepted: int, rejected: int, errors: array } from SaaS,
     *                or wp-remote-post failure shape.
     */
    public function emit_events( string $scan_id, array $events ): array {
        return $this->post( '/cu-scanner/v1/events', [
            'scan_id' => $scan_id,
            'events'  => $events,
        ] );
    }

    private function domain(): string {
        return DomainNormalizer::normalize_url( get_home_url() );
    }

    private function post( string $path, array $body ): array {
        $response = wp_remote_post( $this->base_url . $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode( $body ),
            'timeout' => 15,
        ] );
        return $this->parse( $response );
    }

    private function get( string $path, array $query = [] ): array {
        $url = $this->base_url . $path;
        if ( $query ) {
            $url .= '?' . http_build_query( $query );
        }
        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . $this->api_key ],
            'timeout' => 15,
        ] );
        return $this->parse( $response );
    }

    private function parse( mixed $response ): array {
        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
        if ( $code === 402 ) {
            throw new \RuntimeException( $body['message'] ?? 'Insufficient credits' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        if ( $code < 200 || $code >= 300 ) {
            throw new \RuntimeException( "HTTP {$code}: " . ( $body['message'] ?? 'Unknown error' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        return $body;
    }
}

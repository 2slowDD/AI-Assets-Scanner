<?php
namespace CUScanner\Api;

class RailwayClient {
    public function __construct(
        private readonly string $railway_url,
        private readonly string $api_key
    ) {}

    public function submit_job( array $payload ): array {
        $job_token = isset( $payload['job_token'] ) ? (string) $payload['job_token'] : '';
        if ( $job_token === '' ) {
            throw new \RuntimeException( 'job_token required for Railway submit' );
        }
        $response = wp_remote_post( $this->railway_url . '/jobs', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $job_token,
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ] );
        return $this->parse( $response );
    }

    public function get_status( string $job_id, string $job_token, int $from = 0 ): array {
        $url      = $this->railway_url . "/jobs/{$job_id}/status?from={$from}";
        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . $job_token ],
            'timeout' => 15,
        ] );
        return $this->parse( $response, allow_410: true );
    }

    public function cancel_job( string $job_id, string $job_token ): void {
        $response = wp_remote_post( $this->railway_url . "/jobs/{$job_id}/cancel", [
            'headers' => [ 'Authorization' => 'Bearer ' . $job_token ],
            'timeout' => 15,
        ] );
        $this->parse( $response );
    }

    private function parse( mixed $response, bool $allow_410 = false ): array {
        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
        if ( $allow_410 && $code === 410 ) {
            throw new \RuntimeException( 'Job data expired. Re-download from Scan History or run a new scan.' );
        }
        if ( $code < 200 || $code >= 300 ) {
            throw new \RuntimeException( "Railway HTTP {$code}: " . ( $body['message'] ?? $body['error'] ?? 'error' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        return $body;
    }
}

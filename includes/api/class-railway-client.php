<?php
namespace CUScanner\Api;

defined( 'ABSPATH' ) || exit;

use CUScanner\Settings;

class RailwayClient {
    /**
     * @throws \RuntimeException if $railway_url fails the host/scheme allowlist.
     */
    public function __construct(
        private readonly string $railway_url,
        private readonly string $api_key
    ) {
        if ( ! Settings::is_safe_railway_url( $railway_url ) ) {
            throw new \RuntimeException( 'Railway URL rejected: not on the host allowlist. Re-save Settings to refresh.' );
        }
    }

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

    public function cancel_job( string $job_id, string $job_token ): array {
        $response = wp_remote_post( $this->railway_url . "/jobs/{$job_id}/cancel", [
            'headers' => [ 'Authorization' => 'Bearer ' . $job_token ],
            'timeout' => 15,
        ] );
        // Railway returns { ok: true, pages_completed: N } — callers use pages_completed
        // to record the actual billable count on the local scan history (user_cancel
        // is a charging source; charge amount = pages_completed at cancel-click time).
        return $this->parse( $response );
    }

    private function parse( mixed $response, bool $allow_410 = false ): array {
        if ( is_wp_error( $response ) ) {
            throw new HttpException( $response->get_error_message(), 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
        if ( $allow_410 && $code === 410 ) {
            throw new HttpException( 'Job data expired. Re-download from Scan History or run a new scan.', 410 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        if ( $code < 200 || $code >= 300 ) {
            throw new HttpException( "Railway HTTP {$code}: " . ( $body['message'] ?? $body['error'] ?? 'error' ), (int) $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
        }
        return $body;
    }
}

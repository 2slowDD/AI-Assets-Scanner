<?php
namespace CUScanner\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use CUScanner\Settings;
use CUScanner\ScanHistory;
use CUScanner\Api\WpserviceClient;
use CUScanner\Api\RailwayClient;
use CUScanner\Scanner\PageDiscovery;
use CUScanner\Scanner\PluginDetector;
use CUScanner\Scanner\BypassManager;
use CUScanner\Scanner\CuJsonBuilder;
use CUScanner\Scanner\RulePusher;

class ScannerAjax {
    public function register(): void {
        $actions = [
            'cu_scanner_detect_plugins',
            'cu_scanner_discover_pages',
            'cu_scanner_reserve_job',
            'cu_scanner_submit_job',
            'cu_scanner_poll_status',
            'cu_scanner_cancel_job',
            'cu_scanner_handle_failure',
            'cu_scanner_build_result',
            'cu_scanner_download_json',
            'cu_scanner_push_to_cu',
            'cu_scanner_check_job',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, str_replace( 'cu_scanner_', '', $action ) ] );
        }
    }

    private function check(): void {
        check_ajax_referer( 'cu_scanner_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }
    }

    private function settings(): Settings { return new Settings(); }

    public function detect_plugins(): void {
        $this->check();
        $plugins = ( new PluginDetector() )->detect();

        try {
            $client  = new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $this->settings()->get_api_key() );
            $credits = $client->get_credits();
            $balance = isset( $credits['balance'] ) ? (int) $credits['balance'] : null;
        } catch ( \RuntimeException $e ) {
            error_log( '[AI Assets Scanner] detect_plugins balance: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: exception detail is withheld from the browser and written to server error log only.
            $balance = null;
        }

        wp_send_json_success( array_merge( $plugins, [ 'balance' => $balance ] ) );
    }

    public function discover_pages(): void {
        $this->check();
        $discovery = new PageDiscovery();
        $home_url  = trailingslashit( get_home_url() );
        $sitemap   = $home_url . 'sitemap.xml';
        $urls      = $discovery->discover_from_sitemap( $sitemap );
        if ( empty( $urls ) ) {
            $urls = $discovery->discover_from_wpquery();
        }
        $excluded = array_map( 'sanitize_url', wp_unslash( (array) ( $_POST['excluded_urls'] ?? [] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        $discovery->set_manual_urls( $urls );
        $discovery->set_excluded_urls( $excluded );
        $final = $discovery->get_urls();

        // Build post-type groups via WP_Query.
        // Normalise both map keys and lookup values so sitemap URLs
        // (which may differ in scheme or trailing slash) match get_permalink() output.
        $normalise = fn( string $u ): string => trailingslashit( set_url_scheme( $u, 'https' ) );

        $q = new \WP_Query( [
            'post_type'      => get_post_types( [ 'public' => true ] ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );
        $id_to_type = [];
        foreach ( $q->posts as $id ) {
            $permalink = get_permalink( $id );
            if ( $permalink ) {
                $id_to_type[ $normalise( $permalink ) ] = get_post_type( $id );
            }
        }

        $groups = [ 'page' => [], 'post' => [], 'other' => [] ];
        foreach ( $final as $url ) {
            $type = $id_to_type[ $normalise( $url ) ] ?? 'other';
            if ( $type === 'page' )       $groups['page'][]  = $url;
            elseif ( $type === 'post' )   $groups['post'][]  = $url;
            else                          $groups['other'][] = $url;
        }

        wp_send_json_success( [
            'urls'   => $final,
            'groups' => $groups,
            'count'  => count( $final ),
        ] );
    }

    public function reserve_job(): void {
        $this->check();
        $settings   = $this->settings();
        $page_count = absint( $_POST['page_count'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        if ( $page_count < 1 ) { wp_send_json_error( 'Invalid page count' ); return; }
        try {
            $client = new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $settings->get_api_key() );
            $result = $client->reserve_job( $page_count );
            set_transient( 'cu_scanner_pending_token_' . get_current_user_id(), $result['job_token'], 3600 );
            wp_send_json_success( [ 'reserved' => true, 'job_token' => $result['job_token'] ] );
        } catch ( \RuntimeException $e ) {
            error_log( '[AI Assets Scanner] reserve_job: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: exception detail is withheld from the browser and written to server error log only.
            wp_send_json_error( 'Could not reserve credits. You may not have enough credits for the selected pages — buy more or reduce the number of pages to scan.' );
        }
    }

    public function submit_job(): void {
        $this->check();
        $settings    = $this->settings();
        $railway_url = $settings->get_railway_url();
        $api_key     = $settings->get_api_key();
        $job_token   = sanitize_text_field( wp_unslash( $_POST['job_token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        $urls_raw    = array_map( 'sanitize_url', wp_unslash( (array) ( $_POST['urls'] ?? [] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in $this->check(); URLs sanitized via array_map sanitize_url.

        if ( ! $railway_url || ! $job_token || empty( $urls_raw ) ) {
            wp_send_json_error( 'Missing required fields' ); return;
        }

        // Detect plugins, build bypass params
        $detected = ( new PluginDetector() )->detect();
        $bypass   = new BypassManager();
        $token    = $bypass->create_token();

        $bypass_params = [];
        foreach ( $detected['auto_bypass'] as $params ) {
            foreach ( $params as $param ) {
                $bypass_params[ $param ] = '';
            }
        }

        $pages = array_map(
            fn( $u ) => [
                'url'          => add_query_arg( $bypass_params, sanitize_url( $u ) ),
                'bypass_token' => $token,
            ],
            $urls_raw
        );

        $payload = [
            'pages'          => $pages,
            'job_token'      => $job_token,
            'api_key'        => $api_key,
            'wpservice_url'  => CU_SCANNER_WPSERVICE_BASE,
            'scanner_secret' => $settings->get_scanner_secret(),
        ];
        $http_auth = $settings->get_http_auth();
        if ( $http_auth ) {
            $payload['http_auth'] = $http_auth;
        }

        try {
            $client = new RailwayClient( $railway_url, $api_key );
            $result = $client->submit_job( $payload );
            $job_id = $result['job_id'];

            $domain = wp_parse_url( get_home_url(), PHP_URL_HOST );
            ( new ScanHistory() )->create_record( $job_id, $domain, count( $urls_raw ), 'queued' );

            set_transient( 'cu_scanner_job_' . get_current_user_id(), [
                'job_id'       => $job_id,
                'job_token'    => $job_token,
                'bypass_token' => $token,
                'railway_url'  => $railway_url,
            ], 7200 );

            wp_send_json_success( [
                'job_id'      => $job_id,
                'job_token'   => $job_token,
                'railway_url' => $railway_url,
            ] );
        } catch ( \RuntimeException $e ) {
            $bypass->delete_all_tokens();
            try {
                ( new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $api_key ) )->release_credits( $job_token );
            } catch ( \RuntimeException ) {}
            error_log( '[AI Assets Scanner] submit_job: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: exception detail is withheld from the browser and written to server error log only.
            wp_send_json_error( 'Could not submit scan job. Check server error logs.' );
        }
    }

    public function check_job(): void {
        $this->check();
        $state = get_transient( 'cu_scanner_job_' . get_current_user_id() );
        if ( ! $state ) {
            wp_send_json_error( 'No active job' ); return;
        }
        wp_send_json_success( [
            'job_id'      => $state['job_id'],
            'job_token'   => $state['job_token'],
            'railway_url' => $state['railway_url'],
        ] );
    }

    public function poll_status(): void {
        $this->check();
        $job_id    = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        $job_token = sanitize_text_field( wp_unslash( $_POST['job_token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        $from      = absint( $_POST['from'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        $settings  = $this->settings();
        try {
            $client = new RailwayClient( $settings->get_railway_url(), $settings->get_api_key() );
            $status = $client->get_status( $job_id, $job_token, $from );
            wp_send_json_success( $status );
        } catch ( \RuntimeException $e ) {
            error_log( '[AI Assets Scanner] poll_status: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: exception detail is withheld from the browser and written to server error log only.
            wp_send_json_error( 'Could not retrieve scan status. Check server error logs.' );
        }
    }

    public function cancel_job(): void {
        $this->check();
        $user_id = get_current_user_id();
        $state   = get_transient( 'cu_scanner_job_' . $user_id );
        if ( ! $state ) { wp_send_json_error( 'No active scan' ); return; }

        $settings = $this->settings();
        // Railway's cancel route now owns credit release — no need to call release_credits here.
        try {
            $client = new RailwayClient( $state['railway_url'], $settings->get_api_key() );
            $client->cancel_job( $state['job_id'], $state['job_token'] );
        } catch ( \RuntimeException ) { /* Cancel best-effort */ }

        ( new BypassManager() )->delete_all_tokens();
        ( new ScanHistory() )->update_status( $state['job_id'], 'cancelled' );
        delete_transient( 'cu_scanner_job_' . $user_id );
        wp_send_json_success();
    }

    public function build_result(): void {
        $this->check();
        $job_id    = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        $job_token = sanitize_text_field( wp_unslash( $_POST['job_token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().

        if ( ! $job_id || ! $job_token ) {
            wp_send_json_error( 'Missing job_id or job_token' ); return;
        }

        // Fetch full coverage dataset from Railway server-side.
        $settings = $this->settings();
        try {
            $client = new RailwayClient( $settings->get_railway_url(), $settings->get_api_key() );
            $status = $client->get_status( $job_id, $job_token, 0 );
        } catch ( \RuntimeException $e ) {
            error_log( '[AI Assets Scanner] build_result: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: exception detail is withheld from the browser and written to server error log only.
            wp_send_json_error( 'Could not retrieve scan data. Check server error logs.' ); return;
        }

        $pages_raw = $status['pages'] ?? [];
        if ( empty( $pages_raw ) ) {
            wp_send_json_error( 'No coverage data in Railway response' ); return;
        }

        $cu_json  = ( new CuJsonBuilder() )->build( $pages_raw );
        $json_str = json_encode( $cu_json, JSON_PRETTY_PRINT );

        $safe_count = count( array_filter( $cu_json['rules'], fn($r) => $r['group_id'] === 1 ) );
        $agg_count  = count( array_filter( $cu_json['rules'], fn($r) => $r['group_id'] === 2 ) );
        $completed_pages = count( array_filter( $pages_raw, fn($p) => ( $p['status'] ?? '' ) !== 'error' ) );

        $history = new ScanHistory();
        $history->store_json( $job_id, $json_str );
        $history->update_status( $job_id, 'complete', [
            'credits_used'     => $completed_pages,
            'safe_count'       => $safe_count,
            'aggressive_count' => $agg_count,
        ] );

        ( new BypassManager() )->delete_all_tokens();
        delete_transient( 'cu_scanner_job_' . get_current_user_id() );

        wp_send_json_success( [
            'safe_count'       => $safe_count,
            'aggressive_count' => $agg_count,
            'can_push'         => ( new RulePusher() )->can_push(),
        ] );
    }

    public function handle_failure(): void {
        $this->check();
        $user_id  = get_current_user_id();
        $state    = get_transient( 'cu_scanner_job_' . $user_id );

        // submit_job never ran (e.g. PHP fatal) — release using the pending token from reserve_job.
        if ( ! $state ) {
            $pending = get_transient( 'cu_scanner_pending_token_' . $user_id );
            if ( $pending ) {
                try {
                    $settings = $this->settings();
                    ( new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $settings->get_api_key() ) )
                        ->release_credits( $pending );
                } catch ( \RuntimeException ) {}
                delete_transient( 'cu_scanner_pending_token_' . $user_id );
            }
            ( new BypassManager() )->delete_all_tokens();
            wp_send_json_success();
            return;
        }

        try {
            $settings = $this->settings();
            $wps = new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $settings->get_api_key() );
            $wps->release_credits( $state['job_token'] );
        } catch ( \RuntimeException ) {}

        ( new BypassManager() )->delete_all_tokens();
        ( new ScanHistory() )->update_status( $state['job_id'], 'failed' );
        delete_transient( 'cu_scanner_job_' . $user_id );
        wp_send_json_success();
    }

    public function download_json(): void {
        check_ajax_referer( 'cu_scanner_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        $job_id = sanitize_text_field( wp_unslash( $_GET['job_id'] ?? '' ) );
        $json   = ( new ScanHistory() )->get_json( $job_id );
        if ( ! $json ) { wp_die( 'Not found' ); }
        header( 'Content-Type: application/json' );
        header( 'Content-Disposition: attachment; filename="cu-scanner-' . $job_id . '.json"' );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download served with Content-Disposition: attachment; not rendered as HTML.
        exit;
    }

    public function push_to_cu(): void {
        $this->check();
        $job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        $json   = ( new ScanHistory() )->get_json( $job_id );
        if ( ! $json ) { wp_send_json_error( 'Scan data not found' ); return; }
        $pusher = new RulePusher();
        if ( ! $pusher->can_push() ) { wp_send_json_error( 'Code Unloader not active' ); return; }
        try {
            $decoded   = json_decode( $json, true );
            $site_host = strtolower( preg_replace( '/^www\./i', '', wp_parse_url( get_home_url(), PHP_URL_HOST ) ?? '' ) );
            // Strip rules from external URLs — only push rules belonging to this site.
            // www. prefix is stripped from both sides before comparing.
            // Download (download_json) serves the full unfiltered JSON.
            $decoded['rules'] = array_values( array_filter(
                $decoded['rules'] ?? [],
                function ( $rule ) use ( $site_host ) {
                    $rule_host = strtolower( preg_replace( '/^www\./i', '', wp_parse_url( $rule['url_pattern'] ?? '', PHP_URL_HOST ) ?? '' ) );
                    return $rule_host === $site_host;
                }
            ) );
            $summary = $pusher->push( $decoded );
            wp_send_json_success( $summary );
        } catch ( \Throwable $e ) {
            error_log( '[AI Assets Scanner] push_to_cu: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: exception detail is withheld from the browser and written to server error log only.
            wp_send_json_error( 'Push failed. Check server error logs.' );
        }
    }
}

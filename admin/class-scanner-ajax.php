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
        $result = ( new PluginDetector() )->detect();
        wp_send_json_success( $result );
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
        $excluded = array_map( 'sanitize_url', wp_unslash( (array) ( $_POST['excluded_urls'] ?? [] ) ) );
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
        $page_count = absint( $_POST['page_count'] ?? 0 );
        if ( $page_count < 1 ) { wp_send_json_error( 'Invalid page count' ); return; }
        try {
            $client = new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $settings->get_api_key() );
            $result = $client->reserve_job( $page_count );
            set_transient( 'cu_scanner_pending_token_' . get_current_user_id(), $result['job_token'], 3600 );
            wp_send_json_success( [ 'reserved' => true, 'job_token' => $result['job_token'] ] );
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    public function submit_job(): void {
        $this->check();
        $settings    = $this->settings();
        $railway_url = $settings->get_railway_url();
        $api_key     = $settings->get_api_key();
        $job_token   = sanitize_text_field( wp_unslash( $_POST['job_token'] ?? '' ) );
        $urls_raw    = wp_unslash( (array) ( $_POST['urls'] ?? [] ) );

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
            'pages'         => $pages,
            'job_token'     => $job_token,
            'api_key'       => $api_key,
            'wpservice_url' => CU_SCANNER_WPSERVICE_URL,
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
            ( new ScanHistory() )->create_record( $job_id, $domain, count( $urls_raw ), 'in_progress' );

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
            wp_send_json_error( $e->getMessage() );
        }
    }

    public function poll_status(): void {
        $this->check();
        $job_id    = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
        $job_token = sanitize_text_field( wp_unslash( $_POST['job_token'] ?? '' ) );
        $from      = absint( $_POST['from'] ?? 0 );
        $settings  = $this->settings();
        try {
            $client = new RailwayClient( $settings->get_railway_url(), $settings->get_api_key() );
            $status = $client->get_status( $job_id, $job_token, $from );
            wp_send_json_success( $status );
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    public function cancel_job(): void {
        $this->check();
        $user_id = get_current_user_id();
        $state   = get_transient( 'cu_scanner_job_' . $user_id );
        if ( ! $state ) { wp_send_json_error( 'No active scan' ); return; }

        $settings = $this->settings();
        try {
            $client = new RailwayClient( $state['railway_url'], $settings->get_api_key() );
            $client->cancel_job( $state['job_id'], $state['job_token'] );
        } catch ( \RuntimeException ) { /* Cancel best-effort */ }

        try {
            $wps = new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $settings->get_api_key() );
            $wps->release_credits( $state['job_token'] );
        } catch ( \RuntimeException ) {}

        ( new BypassManager() )->delete_all_tokens();
        ( new ScanHistory() )->update_status( $state['job_id'], 'cancelled' );
        delete_transient( 'cu_scanner_job_' . $user_id );
        wp_send_json_success();
    }

    public function build_result(): void {
        $this->check();
        $job_id    = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
        $job_token = sanitize_text_field( wp_unslash( $_POST['job_token'] ?? '' ) );

        if ( ! $job_id || ! $job_token ) {
            wp_send_json_error( 'Missing job_id or job_token' ); return;
        }

        // Fetch full coverage dataset from Railway server-side.
        $settings = $this->settings();
        try {
            $client = new RailwayClient( $settings->get_railway_url(), $settings->get_api_key() );
            $status = $client->get_status( $job_id, $job_token, 0 );
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( 'Could not retrieve scan data: ' . $e->getMessage() ); return;
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
        $user_id = get_current_user_id();
        $state   = get_transient( 'cu_scanner_job_' . $user_id );
        if ( ! $state ) { wp_send_json_success(); return; }

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
        echo $json;
        exit;
    }

    public function push_to_cu(): void {
        $this->check();
        $job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
        $json   = ( new ScanHistory() )->get_json( $job_id );
        if ( ! $json ) { wp_send_json_error( 'Scan data not found' ); return; }
        $pusher = new RulePusher();
        if ( ! $pusher->can_push() ) { wp_send_json_error( 'Code Unloader not active' ); return; }
        try {
            $summary = $pusher->push( json_decode( $json, true ) );
            wp_send_json_success( $summary );
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }
}

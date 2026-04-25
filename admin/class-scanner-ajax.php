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
use CUScanner\Scanner\EventEmitter;
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
            'cu_scanner_export_history',
            'cu_scanner_delete_history',
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
            error_log( '[AI Assets Scanner] reserve_job: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: full exception detail to server log; truncated user-visible detail via format_reserve_error_detail().
            wp_send_json_error( self::format_reserve_error_detail( $e->getMessage() ) );
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
        $detector       = new PluginDetector();
        $detected       = $detector->detect();
        $detector_typed = $detector->detect_typed();
        $bypass         = new BypassManager();
        $token          = $bypass->create_token();

        $bypass_params   = [];
        foreach ( $detected['auto_bypass'] as $params ) {
            foreach ( $params as $param ) {
                $bypass_params[ $param ] = '';
            }
        }

        $bypass_suffixes = PluginDetector::build_bypass_suffixes( $detector_typed );

        $pages = array_map(
            fn( $u ) => [
                'url'             => add_query_arg( $bypass_params, sanitize_url( $u ) ),
                'bypass_token'    => $token,
                'bypass_suffixes' => $bypass_suffixes,
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

            // Derive a stable scan_id from the Railway job_id (16 hex chars).
            $scan_id    = substr( hash( 'sha256', (string) $job_id ), 0, 16 );
            $primary_url = (string) ( $urls_raw[0] ?? '' );

            EventEmitter::emit(
                'scan_request_received',
                'operational',
                [
                    'scan_id'           => $scan_id,
                    'path_hash'         => substr( hash( 'sha256', $primary_url ), 0, 16 ),
                    'optimizers_active' => count( $detector_typed ),
                ],
                $scan_id
            );

            foreach ( $detector_typed as $file => $entry ) {
                EventEmitter::emit(
                    'optimizer_detected',
                    'operational',
                    [
                        'plugin'       => PluginDetector::plugin_file_to_enum( $file ),
                        'class'        => $entry['class'] ?? '',
                        'bypass_query' => substr( hash( 'sha256', (string) ( $entry['bypass_query'] ?? '' ) ), 0, 16 ),
                        'scan_id'      => $scan_id,
                    ],
                    $scan_id
                );
            }

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
            error_log( '[AI Assets Scanner] submit_job: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: full exception detail to server log; truncated user-visible detail via format_submit_error_detail().
            wp_send_json_error( self::format_submit_error_detail( $e->getMessage() ) );
        }
    }

    /**
     * Formats an exception message from submit_job() failures for user-visible display.
     *
     * The underlying RailwayClient::parse() throws "Railway HTTP {code}: {body.message}".
     * We surface that to the browser (was previously swallowed into a generic message)
     * but truncate to 80 chars to bound what a malformed response could echo.
     *
     * Sub-spec B rollout surfaced that "Could not submit scan job. Check server error
     * logs." is operationally useless — admins need the HTTP status and body extract
     * to diagnose without SSH + tail.
     *
     * @param string $message Exception message (from $e->getMessage()).
     * @return string Formatted user-visible detail, prefixed with "Scan submission failed: ".
     */
    public static function format_submit_error_detail( string $message ): string {
        return 'Scan submission failed: ' . self::truncate_error_detail( $message );
    }

    /**
     * Formats an exception message from reserve_job() failures for user-visible display.
     *
     * Same truncation contract as format_submit_error_detail() — kept as a separate
     * method so callers explicitly pick the user-facing prefix appropriate for each
     * AJAX handler. Underlying WpserviceClient throws "HTTP {code}: {body.message}"
     * for rate-limited (429), insufficient-credits (402), scan-in-progress (409), etc.
     * Surfacing the message lets admins distinguish these conditions.
     *
     * @param string $message Exception message (from $e->getMessage()).
     * @return string Formatted user-visible detail, prefixed with "Could not reserve credits: ".
     */
    public static function format_reserve_error_detail( string $message ): string {
        return 'Could not reserve credits: ' . self::truncate_error_detail( $message );
    }

    /**
     * Core truncation: 80-char cap, ellipsis on overflow. Shared by submit + reserve formatters.
     *
     * @param string $message Raw exception message.
     * @return string Truncated message, ellipsis appended if > 80 chars.
     */
    private static function truncate_error_detail( string $message ): string {
        $detail = mb_substr( $message, 0, 80 );
        if ( mb_strlen( $message ) > 80 ) {
            $detail .= '…';
        }
        return $detail;
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
        // Capture the response so we can record the actual credits charged in our local
        // scan history. Railway returns pages_completed = the count at cancel-click time,
        // which equals the credits charged by the SaaS (user_cancel is a charging source).
        $pages_completed = 0;
        try {
            $client = new RailwayClient( $state['railway_url'], $settings->get_api_key() );
            $resp   = $client->cancel_job( $state['job_id'], $state['job_token'] );
            $pages_completed = (int) ( $resp['pages_completed'] ?? 0 );
        } catch ( \RuntimeException ) { /* Cancel best-effort — keep pages_completed=0 as fallback */ }

        ( new BypassManager() )->delete_all_tokens();
        ( new ScanHistory() )->update_status( $state['job_id'], 'cancelled', [
            'credits_used' => $pages_completed,
        ] );
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

    public function delete_history(): void {
        $this->check();
        $history = new ScanHistory();
        $count   = $history->delete_all();
        set_transient( 'cu_scanner_history_deleted_notice', $count, 30 );
        wp_send_json_success( [ 'deleted' => $count ] );
    }

    /**
     * Defuses CSV formula injection. If the first byte is = + - @ TAB CR,
     * prefix a single quote. Returns the value unchanged otherwise.
     */
    private function csv_cell( string $value ): string {
        if ( $value === '' ) return '';
        $first = $value[0];
        if ( $first === '=' || $first === '+' || $first === '-' || $first === '@'
            || $first === "\t" || $first === "\r" ) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Writes BOM + header row + one data row per record to the given resource.
     * Uses fputcsv for RFC 4180 quoting. Defuses every cell via csv_cell().
     */
    private function write_csv( $resource, array $records ): void {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- $resource is a caller-supplied stream handle (php://memory or php://output), not a filesystem path; WP_Filesystem does not operate on stream wrappers.
        fwrite( $resource, "\xEF\xBB\xBF" );
        fputcsv( $resource, [ 'Date', 'Domain', 'Pages', 'Credits', 'Safe Rules', 'Aggressive Rules', 'Status', 'Job ID' ] );
        foreach ( $records as $r ) {
            $row = [
                (string) ( $r['created_at']       ?? '' ),
                (string) ( $r['domain']           ?? '' ),
                (string) ( $r['page_count']       ?? '' ),
                (string) ( $r['credits_used']     ?? '' ),
                (string) ( $r['safe_count']       ?? '' ),
                (string) ( $r['aggressive_count'] ?? '' ),
                (string) ( $r['status']           ?? '' ),
                (string) ( $r['job_id']           ?? '' ),
            ];
            fputcsv( $resource, array_map( [ $this, 'csv_cell' ], $row ) );
        }
    }

    protected function zip_available(): bool {
        return class_exists( 'ZipArchive' );
    }

    protected function terminate(): void {
        exit;
    }

    /**
     * Emits Content-Type and Content-Disposition headers.
     * Seam point for test override (to avoid header() errors after output has started).
     */
    protected function emit_csv_headers( string $filename ): void {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    }

    private function stream_csv_response( array $records ): void {
        $filename = 'ai-assets-scanner-history-' . gmdate( 'Y-m-d-His' ) . '.csv';
        $this->emit_csv_headers( $filename );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is the HTTP response body stream, not a filesystem file; WP_Filesystem cannot target it.
        $fh = fopen( 'php://output', 'w' );
        $this->write_csv( $fh, $records );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with fopen on php://output above; stream wrapper, not a filesystem file.
        fclose( $fh );
        $this->terminate();
    }

    /**
     * Builds the ZIP at $tmp_path. Returns true on success, false on any
     * ZipArchive failure (at which point $tmp_path has been @unlink'd).
     * Populates $missing_snapshots (by reference) with job_ids that had no
     * stored snapshot.
     */
    private function build_zip( string $tmp_path, array $records, ScanHistory $history, array &$missing_snapshots ): bool {
        $zip = new \ZipArchive();
        $rc  = $zip->open( $tmp_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
        if ( $rc !== true ) {
            error_log( '[AI Assets Scanner] ZipArchive::open failed: ' . $rc ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug logging only.
            wp_delete_file( $tmp_path );
            return false;
        }

        $zip->addFromString( 'history.json', (string) wp_json_encode( $records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

        // Generate CSV to a string via php://memory so we can addFromString.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-memory stream used to buffer CSV for addFromString; WP_Filesystem does not operate on stream wrappers.
        $mem = fopen( 'php://memory', 'w+' );
        $this->write_csv( $mem, $records );
        rewind( $mem );
        $csv = stream_get_contents( $mem );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with fopen on php://memory above.
        fclose( $mem );
        $zip->addFromString( 'history.csv', $csv );

        $missing_snapshots = [];
        foreach ( $records as $r ) {
            $job_id = isset( $r['job_id'] ) ? (string) $r['job_id'] : '';
            if ( $job_id === '' ) continue;
            // Defensive: strip chars that could escape the archive path.
            $safe = preg_replace( '/[^A-Za-z0-9._-]/', '', $job_id );
            if ( $safe === '' ) continue;
            $snapshot = $history->get_json( $safe );
            if ( $snapshot === '' ) {
                $missing_snapshots[] = $safe;
                continue;
            }
            $zip->addFromString( 'scans/' . $safe . '.json', $snapshot );
        }

        $readme  = 'AI Assets Scanner v' . CU_SCANNER_VERSION . "\n";
        $readme .= 'Export timestamp: ' . gmdate( 'c' ) . "\n";
        $readme .= 'Records: ' . count( $records ) . "\n";
        if ( ! empty( $missing_snapshots ) ) {
            $readme .= 'Missing snapshots: ' . implode( ', ', $missing_snapshots ) . "\n";
        }
        $zip->addFromString( 'README.txt', $readme );

        if ( $zip->close() !== true ) {
            error_log( '[AI Assets Scanner] ZipArchive::close failed' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug logging only.
            wp_delete_file( $tmp_path );
            return false;
        }
        return true;
    }

    protected function stream_zip( string $tmp_path ): void {
        $filename = 'ai-assets-scanner-history-' . gmdate( 'Y-m-d-His' ) . '.zip';
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $tmp_path ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams server-generated wp_tempnam ZIP directly to HTTP response body; WP has no equivalent for binary pass-through, and loading via file_get_contents would blow memory on large archives.
        readfile( $tmp_path );
        wp_delete_file( $tmp_path );
        $this->terminate();
    }

    public function export_history(): void {
        check_ajax_referer( 'cu_scanner_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', '', [ 'response' => 403 ] );
        }
        $records = ( new ScanHistory() )->get_all();
        if ( empty( $records ) ) {
            wp_die( 'No history to export', '', [ 'response' => 200 ] );
        }
        if ( ! $this->zip_available() ) {
            $this->stream_csv_response( $records );
            return; // unreachable in prod; reachable under test seam
        }
        // ZIP primary path.
        $tmp = wp_tempnam( 'cu-scanner-history' );
        $missing = [];
        $history = new ScanHistory();
        if ( $this->build_zip( $tmp, $records, $history, $missing ) ) {
            $this->stream_zip( $tmp );
            return;
        }
        // Fall through to CSV-only if ZIP build failed.
        $this->stream_csv_response( $records );
    }
}

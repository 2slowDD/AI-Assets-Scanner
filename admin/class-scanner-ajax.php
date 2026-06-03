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
            'cu_scanner_handle_killed',
            'cu_scanner_build_result',
            'cu_scanner_download_json',
            'cu_scanner_push_to_cu',
            'cu_scanner_sync_to_cu',
            'cu_scanner_check_job',
            'cu_scanner_export_history',
            'cu_scanner_delete_history',
            'cu_scanner_probe_target_stack',
            'cu_scanner_get_badge_state',
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

    private function ratchet_enabled(): bool {
        return (bool) get_option( 'cu_scanner_ratchet_enabled', false );
    }

    private function ensure_railway_url( Settings $settings, string $api_key ): string {
        $railway_url = $settings->get_railway_url();
        if ( Settings::is_safe_railway_url( $railway_url ) ) {
            return $railway_url;
        }

        if ( '' === $api_key || $settings->is_pending_free_key( $api_key ) ) {
            return '';
        }

        $auth        = ( new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $api_key ) )->authenticate();
        $railway_url = (string) ( $auth['railway_url'] ?? '' );
        if ( '' === $railway_url ) {
            throw new \RuntimeException( 'SaaS auth response did not include Railway URL.' );
        }

        $settings->set_railway_url( $railway_url );
        return $railway_url;
    }

    private function release_reserved_job( string $api_key, string $job_token ): void {
        if ( '' === $api_key || '' === $job_token ) {
            return;
        }

        try {
            ( new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $api_key ) )->release_credits( $job_token );
        } catch ( \RuntimeException ) {}
    }

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
        $extra_time_count = absint( $_POST['extra_time_count'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        if ( $page_count < 1 ) { wp_send_json_error( 'Invalid page count' ); return; }
        if ( $settings->has_pending_free_key() ) {
            wp_send_json_error( 'Free API key activation is pending. Please try again later.' );
            return;
        }
        try {
            $api_key = $settings->get_api_key();
            $this->ensure_railway_url( $settings, $api_key );
            $client = new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $api_key );
            $result = $client->reserve_job( $page_count, $extra_time_count );
            set_transient( 'cu_scanner_pending_token_' . get_current_user_id(), $result['job_token'], 3600 );
            wp_send_json_success( [ 'reserved' => true, 'job_token' => $result['job_token'] ] );
        } catch ( \RuntimeException $e ) {
            error_log( '[AI Assets Scanner] reserve_job: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: full exception detail to server log; truncated user-visible detail via format_reserve_error_detail().
            wp_send_json_error( self::format_reserve_error_detail( $e->getMessage() ) );
        }
    }

    public function submit_job(): void {
        $this->check();
        // Wipe all prior banner dismissals — each new scan gets a fresh slate.
        // After $this->check() so the state-change is gated by nonce + capability per WP Compliance Rules 4/11.
        // Leading backslash: AIAS_Broken_Banner is in the global namespace; this file is in CUScanner\Admin.
        \AIAS_Broken_Banner::on_submit_job();

        $settings    = $this->settings();
        $api_key     = $settings->get_api_key();
        $job_token   = sanitize_text_field( wp_unslash( $_POST['job_token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        $urls_raw    = array_map( 'sanitize_url', wp_unslash( (array) ( $_POST['urls'] ?? [] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in $this->check(); URLs sanitized via array_map sanitize_url.

        // FU-AAS-EXTRA-TIME (UI Task 4) — per-URL Extra Time flag. JS sends the
        // subset of selected URLs the operator marked for Extra Time. Sanitize as
        // URLs (same recognized sanitizer as $urls_raw), then array_flip into a
        // membership set so build_pages_array() can stamp each page spec.
        $et_urls_raw = array_map( 'sanitize_url', wp_unslash( (array) ( $_POST['extra_time_urls'] ?? [] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in $this->check(); URLs sanitized via array_map sanitize_url.
        $et_set      = array_flip( $et_urls_raw );

        try {
            $railway_url = $this->ensure_railway_url( $settings, $api_key );
        } catch ( \RuntimeException $e ) {
            $this->release_reserved_job( $api_key, $job_token );
            error_log( '[AI Assets Scanner] submit_job railway_url: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: no secrets, only auth/allowlist failure detail for diagnosis.
            wp_send_json_error( self::format_submit_error_detail( $e->getMessage() ) );
            return;
        }

        $missing = [];
        if ( ! $railway_url ) {
            $missing[] = 'railway_url';
        }
        if ( ! $job_token ) {
            $missing[] = 'job_token';
        }
        if ( empty( $urls_raw ) ) {
            $missing[] = 'urls';
        }
        if ( $missing ) {
            $this->release_reserved_job( $api_key, $job_token );
            error_log( '[AI Assets Scanner] submit_job missing required fields: ' . implode( ',', $missing ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: field names only, no credentials or URLs.
            wp_send_json_error( 'Missing required fields: ' . implode( ', ', $missing ) );
            return;
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

        $host_bypass = PluginDetector::build_bypass_suffixes( $detector_typed );

        // FU-NEW-2 Phase 5 (T5.2) — capture per-URL bypass map from JS probe step.
        // External URLs use target-detected suffixes; internal URLs use $host_bypass.
        // Missing external URLs default to [] and fire cu_scanner_target_bypass_missing.
        //
        // wp-compliance Rule 25 / proposed-Rule-27 — $_POST may carry a structured
        // multi-level map (URL key → suffix-array). PHP's $_POST parser hands us
        // nested arrays without per-value unslash beyond the outer level. Walk the
        // structure: validate each URL key, validate each suffix value's character
        // class (must match the legal bypass-suffix shape produced by OPTIMIZERS).
        // Anything outside that allowlist is dropped silently.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        $target_bypass_per_url_raw = isset( $_POST['target_bypass_per_url'] )
            ? (array) wp_unslash( $_POST['target_bypass_per_url'] )
            : [];
        $target_bypass_per_url = [];
        foreach ( $target_bypass_per_url_raw as $u => $suffixes ) {
            $clean_url = esc_url_raw( (string) $u );
            if ( $clean_url === '' || ! is_array( $suffixes ) ) {
                continue;
            }
            $clean_suffixes = [];
            foreach ( $suffixes as $s ) {
                $candidate = (string) $s;
                // Legal bypass-suffix shapes: bare flag (e.g. 'nowprocket')
                // or 'key=value' (e.g. 'ao_noptimize=1', 'LSCWP_CTRL=before_optm').
                // Allowed chars: A-Z a-z 0-9 _ - . =
                if ( preg_match( '/^[A-Za-z0-9_=.\-]+$/', $candidate ) ) {
                    $clean_suffixes[] = $candidate;
                }
            }
            $target_bypass_per_url[ $clean_url ] = $clean_suffixes;
        }

        // AC-RC-8a — per-URL submitted_url map. JS sends a parallel submitted_urls[]
        // array, index-aligned with urls[]: urls[i] is the RESOLVED (post-redirect)
        // scan URL, submitted_urls[i] is the ORIGINAL URL the operator entered. We zip
        // them by index into a (resolved-URL → submitted-URL) map keyed the same way
        // build_pages_array() iterates ($urls_raw), so the lookup mirrors
        // $target_bypass_per_url[$url]. Flat scalar array → esc_url_raw per element is a
        // recognized outermost sanitizer (wp-compliance Rule 24).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in $this->check(); URLs sanitized via array_map esc_url_raw.
        $submitted_urls_raw    = array_map( 'esc_url_raw', wp_unslash( (array) ( $_POST['submitted_urls'] ?? [] ) ) );
        $submitted_url_per_url = [];
        foreach ( $urls_raw as $i => $resolved_url ) {
            $orig = $submitted_urls_raw[ $i ] ?? '';
            if ( $resolved_url !== '' && $orig !== '' ) {
                $submitted_url_per_url[ $resolved_url ] = $orig;
            }
        }

        $home_url    = home_url();
        $home_host   = wp_parse_url( $home_url, PHP_URL_HOST );
        $page_specs  = self::build_pages_array( $urls_raw, $host_bypass, $target_bypass_per_url, $home_url, $et_set, $submitted_url_per_url );

        // Force URL scheme to match the current admin request's protocol. Sitemaps
        // and WP_Query can emit http URLs even on https-served sites (option drift,
        // CDN/proxy setups, or sitemap generators that hardcode the scheme). On a
        // 1000-page scan, an http→https redirect on each page costs ~50-100ms × N =
        // 50-100 seconds of wasted Playwright time. is_ssl() reflects the protocol
        // the operator is actually using to manage the site; assume reachable.
        $site_scheme = is_ssl() ? 'https' : 'http';

        // Bake every detected bypass key (old auto_bypass + new typed-detector A/A_star)
        // into the URL itself so:
        //   1. The user can see exactly which keys are being applied (UX/verifiability).
        //   2. Railway's verifier — which currently does NOT receive bypass_suffixes —
        //      navigates to the same fully-bypassed URL during Pass 3 + Pass 4.
        //   3. We don't rely on Railway-side appendQueryParams() for suffix application.
        // bypass_suffixes are bare flags (or `key=value` for Autoptimize/LiteSpeed) and
        // come from PluginDetector::OPTIMIZERS — static strings, not user input — so
        // direct concatenation is safe.
        //
        // FU-NEW-2 Phase 5: each URL gets its own per-URL suffix list (passed in via
        // $page_specs from build_pages_array). External URLs may have an empty list.
        $build_scan_url = static function ( string $u, array $bypass_suffixes ) use ( $bypass_params, $site_scheme, $home_host ): string {
            $sanitized = set_url_scheme( sanitize_url( $u ), $site_scheme );

            // FU-NEW-9 (1.3.5) — only apply operator-site $bypass_params
            // (auto_bypass keys from the LOCAL detector — nowprocket /
            // nowpcu / perfmattersoff / etc. for plugins installed on the
            // operator's OWN WP host) to same-host URLs. External URLs
            // receive ONLY the target-detected $bypass_suffixes from the
            // FU-NEW-2 probe. F-DEG: mixing operator-site keys onto
            // external scan URLs pollutes the target's request with
            // unexpected query params from a different site's plugin
            // config (e.g. bestdiagnostics.net was receiving wpservice.pro's
            // nowprocket+nowpcu alongside its own LSCWP_CTRL=before_optm).
            $url_host     = wp_parse_url( $sanitized, PHP_URL_HOST );
            $is_same_host = ( $url_host && $home_host
                              && strcasecmp( $url_host, $home_host ) === 0 );
            $with_old     = $is_same_host
                ? add_query_arg( $bypass_params, $sanitized )
                : $sanitized;

            if ( empty( $bypass_suffixes ) ) {
                return $with_old;
            }
            // Dedupe suffixes against keys already in $with_old's query string so we
            // don't emit duplicates when both detectors agreed (e.g. nowprocket).
            $existing_keys = [];
            $existing_qs   = wp_parse_url( $with_old, PHP_URL_QUERY );
            if ( is_string( $existing_qs ) && $existing_qs !== '' ) {
                foreach ( explode( '&', $existing_qs ) as $pair ) {
                    if ( $pair === '' ) continue;
                    $eq  = strpos( $pair, '=' );
                    $key = $eq === false ? $pair : substr( $pair, 0, $eq );
                    $existing_keys[ $key ] = true;
                }
            }
            $append = [];
            foreach ( $bypass_suffixes as $s ) {
                $eq  = strpos( $s, '=' );
                $key = $eq === false ? $s : substr( $s, 0, $eq );
                if ( isset( $existing_keys[ $key ] ) ) continue;
                $existing_keys[ $key ] = true;
                $append[] = $s;
            }
            if ( empty( $append ) ) return $with_old;
            $sep = ( strpos( $with_old, '?' ) === false ) ? '?' : '&';
            return $with_old . $sep . implode( '&', $append );
        };

        $pages = self::reshape_page_specs( $page_specs, $build_scan_url, $token );

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

        // FU-NEW-2 Phase 5 (T5.4) — forward target_stack_summary blob to SaaS (AC-N2-10).
        // JS attaches this after the cu_scanner_probe_target_stack step; if absent/empty,
        // the field is omitted from the SaaS payload (null signal from the helper).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        $target_stack_summary_raw = isset( $_POST['target_stack_summary'] )
            ? wp_unslash( $_POST['target_stack_summary'] )
            : null;
        $target_stack_summary = self::capture_target_stack_summary( $target_stack_summary_raw );
        if ( $target_stack_summary !== null ) {
            $payload['target_stack_summary'] = $target_stack_summary;
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

            // Class C orchestrator gate (spec §3.5 + §6.1).
            $class_c_entries = array_filter(
                $detector_typed,
                static fn( $e ) => ( $e['class'] ?? '' ) === 'C'
            );

            if ( ! empty( $class_c_entries ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at top of submit_job via $this->check() / check_ajax_referer().
                $consent = isset( $_POST['class_c_consent_given'] ) ? sanitize_text_field( wp_unslash( $_POST['class_c_consent_given'] ) ) : '';
                if ( $consent !== '1' ) {
                    wp_send_json( [
                        'ok'             => false,
                        'error'          => 'class_c_consent_required',
                        'class_c_active' => array_values( array_map(
                            static fn( $e ) => [
                                'slug'    => (string) ( $e['disable_method'] ?? '' ),
                                'name'    => (string) ( $e['name'] ?? '' ),
                                'warning' => (string) ( $e['warning'] ?? '' ),
                            ],
                            $class_c_entries
                        ) ),
                    ] );
                    return;
                }

                $strategies = [];
                foreach ( $class_c_entries as $entry ) {
                    $method = $entry['disable_method'] ?? '';
                    if ( $method === '' ) continue;
                    try {
                        $strategies[] = \CUScanner\Scanner\StrategyFactory::for_method( $method );
                    } catch ( \InvalidArgumentException $_ ) {
                        // Unknown method: silently skip (factory may lag detector additions).
                    }
                }

                if ( ! empty( $strategies ) ) {
                    try {
                        ( new \CUScanner\Scanner\OptimizerBypassOrchestrator( $strategies ) )
                            ->begin( $scan_id, 1800 );
                    } catch ( \RuntimeException $e ) {
                        wp_send_json( [
                            'ok'      => false,
                            'error'   => 'class_c_disable_failed',
                            'message' => $e->getMessage(),
                        ] );
                        return;
                    }
                }
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
            $this->release_reserved_job( $api_key, $job_token );
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
     * Count pages that should be billed as credits.
     * Excludes 'error' (scan error) and 'origin_unavailable' (origin down — page was skipped).
     *
     * @param array<int, array<string, mixed>> $pages_raw Per-page status rows from Railway.
     * @return int Number of billable pages.
     */
    public static function billable_page_count( array $pages_raw ): int {
        $skip = [ 'error', 'origin_unavailable' ];
        return count( array_filter( $pages_raw, fn( $p ) => ! in_array( $p['status'] ?? '', $skip, true ) ) );
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

        try {
            $result = $this->do_build_result( $job_id, $job_token );
        } catch ( \RuntimeException $e ) {
            error_log( '[AI Assets Scanner] build_result: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: exception detail is withheld from the browser and written to server error log only.
            wp_send_json_error( 'Could not retrieve scan data. Check server error logs.' ); return;
        }
        // 1.4.6 — AAS-page-side completion marks-as-seen immediately. This AJAX
        // handler is called by scanner.js's polling loop on the AAS scanner page,
        // so the operator is actively viewing the result. Marking seen here avoids
        // the badge-flash-on-next-nav timing race where mark_seen_on_main_page
        // (admin_head hook) ran BEFORE this AJAX completed — at admin_head time
        // ScanHistory still had status='queued', so mark_seen early-returned
        // without updating aias_last_seen_scan_id, leaving the badge to fire on
        // the next non-AAS navigation. The server-side Heartbeat path
        // (MenuBadge::check_active_job_completion) intentionally does NOT call
        // update_option here because the operator IS away from AAS in that case
        // and the badge SHOULD fire.
        update_option( 'aias_last_seen_scan_id', $job_id );
        wp_send_json_success( $result );
    }

    /**
     * Build the scan result server-side from Railway coverage data.
     *
     * Refactored in 1.4.5 — extracted from the build_result() AJAX handler so
     * that MenuBadge::check_active_job_completion() (server-side Heartbeat-driven
     * background polling) can complete a scan without an active AAS-page JS
     * client. Throws RuntimeException on Railway fetch errors or empty coverage;
     * returns the same response payload the AJAX handler emits (used both as
     * wp_send_json_success arg and consumed directly by the Heartbeat path).
     *
     * @throws \RuntimeException Railway fetch error or empty coverage data.
     */
    public function do_build_result( string $job_id, string $job_token ): array {
        // Fetch full coverage dataset from Railway server-side.
        $settings = $this->settings();
        $client   = new RailwayClient( $settings->get_railway_url(), $settings->get_api_key() );
        $status   = $client->get_status( $job_id, $job_token, 0 );

        $pages_raw = $status['pages'] ?? [];
        if ( empty( $pages_raw ) ) {
            throw new \RuntimeException( 'No coverage data in Railway response' );
        }

        // Per Rule 1, $status is the Railway HTTP response — untrusted. Guard
        // with is_array; (bool)(... ?? false) casts inside build() handle the rest.
        $flags   = isset( $status['flags'] ) && is_array( $status['flags'] ) ? $status['flags'] : [];
        $cu_json = ( new CuJsonBuilder() )->build( $pages_raw, $flags );

        // B2 — persist R_orig on non-ET scans so a subsequent ET rescan can ratchet against it.
        if ( $this->ratchet_enabled() && ! $this->is_et_rescan( $pages_raw ) ) {
            $this->persist_r_orig( $cu_json, $pages_raw );
        }

        // B3 — ET-ratchet merge: replace cu_json['rules'] + recompute by_page BEFORE store_json.
        // This ensures the stored JSON (read verbatim by push_to_cu) reflects merged rules.
        // $merger is kept in scope so recovered_by_pattern is available for the pages_payload below.
        $merger = null;
        if ( $this->ratchet_enabled() && $this->is_et_rescan( $pages_raw ) ) {
            $r_orig = get_transient( 'cu_scanner_r_orig_' . get_current_user_id() );
            if ( $this->r_orig_matches( $r_orig, $pages_raw ) ) {
                $orig_by_page       = $cu_json['by_page'];
                $merger             = new \CUScanner\Scanner\RatchetMerger();
                $cu_json['rules']   = $merger->merge( $r_orig['rules'], $pages_raw, $flags );
                $cu_json['by_page'] = $this->recompute_by_page( $cu_json['rules'], $pages_raw, $orig_by_page );
            }
        }

        $json_str = json_encode( $cu_json, JSON_PRETTY_PRINT );

        $safe_count = count( array_filter( $cu_json['rules'], fn($r) => $r['group_id'] === 1 ) );
        $agg_count  = count( array_filter( $cu_json['rules'], fn($r) => $r['group_id'] === 2 ) );
        $completed_pages = self::billable_page_count( $pages_raw );

        $history = new ScanHistory();
        $history->store_json( $job_id, $json_str );
        $history->update_status( $job_id, 'complete', [
            'credits_used'     => $completed_pages,
            'safe_count'       => $safe_count,
            'aggressive_count' => $agg_count,
        ] );

        // Signal scan completion so the Class C orchestrator can restore plugins (spec §3.5).
        $scan_id_complete = substr( hash( 'sha256', (string) $job_id ), 0, 16 );
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'cu_scanner_*' is the long-standing internal prefix shared with the wpservice-saas backend and the Railway worker; renaming would break inter-component contracts.
        do_action( 'cu_scanner_scan_complete', $scan_id_complete );

        ( new BypassManager() )->delete_all_tokens();
        delete_transient( 'cu_scanner_job_' . get_current_user_id() );

        // Compute pages_blocked + blocked_reasons from Railway status for Subsystem D-4 banner.
        // Railway per-page shape (Task 8 / Subsystem D-1):
        //   { url, status, assets, broken_devices?: [{device, is_broken, reason, http_status, body_bytes}] }
        // broken_devices is an ARRAY inside the page object — NOT top-level 'device'/'blocked_reason' fields.
        // Each entry represents one device (desktop or mobile) that was blocked.
        $pages_blocked  = [ 'desktop' => 0, 'mobile' => 0 ];
        $blocked_reasons = [];
        $seen_blocked   = [ 'desktop' => [], 'mobile' => [] ]; // track per-device to count each page once
        foreach ( $pages_raw as $page ) {
            $broken_devices = is_array( $page['broken_devices'] ?? null ) ? $page['broken_devices'] : [];
            foreach ( $broken_devices as $bd ) {
                $device = (string) ( $bd['device'] ?? '' );
                $reason = (string) ( $bd['reason'] ?? '' );
                if ( $reason === '' ) {
                    continue;
                }
                // Count each device blocked once per page (broken_devices can have at most one desktop + one mobile entry).
                $page_url = (string) ( $page['url'] ?? '' );
                if ( $device === 'mobile' ) {
                    if ( ! isset( $seen_blocked['mobile'][ $page_url ] ) ) {
                        $pages_blocked['mobile']++;
                        $seen_blocked['mobile'][ $page_url ] = true;
                    }
                } elseif ( $device === 'desktop' ) {
                    if ( ! isset( $seen_blocked['desktop'][ $page_url ] ) ) {
                        $pages_blocked['desktop']++;
                        $seen_blocked['desktop'][ $page_url ] = true;
                    }
                }
                $blocked_reasons[ $reason ] = ( $blocked_reasons[ $reason ] ?? 0 ) + 1;
            }
        }

        // FU-NEW-X-A (2026-05-17 PM late): defensive fallback for the Subsystem D-4 banner.
        // Some scan-error paths populate `status: 'error'` on a page but DON'T populate
        // `broken_devices` (e.g., analyzePage's outer catch at page-analyzer.js:893
        // returns `{url, status:'error', assets:[]}` without broken_devices; certain
        // pre-runPass failure modes also bypass the broken_devices construction).
        // Without this fallback the banner silently disappears for external scans that
        // errored — operator reported regression 2026-05-17 PM. When the broken_devices
        // walk above yielded zero pages_blocked BUT some pages have `status === 'error'`,
        // count those errored pages as blocked-on-both-devices with reason `scan_errored`
        // (a synthetic reason for this fallback path; mapped to the 'error' action_clause
        // category in scanner.js phraseMap + reasonCategory()).
        if ( $pages_blocked['desktop'] === 0 && $pages_blocked['mobile'] === 0 ) {
            foreach ( $pages_raw as $page ) {
                if ( ( $page['status'] ?? '' ) === 'error' ) {
                    $pages_blocked['desktop']++;
                    $pages_blocked['mobile']++;
                    $blocked_reasons['scan_errored'] = ( $blocked_reasons['scan_errored'] ?? 0 ) + 1;
                }
            }
        }

        // 12-char scan_id for display: matches the SaaS/Railway canonical id (they
        // truncate the 16-char submit-time scan_id to 12). Bug-fix (1.5.4).
        $scan_id_display = substr( $scan_id_complete, 0, 12 );

        // Per-URL Step-4 results table. by_page is keyed by the same $pages_raw
        // index, so build_pages() joins status/credits with S/A/N tallies cleanly.
        // Leading backslash: AIAS_Scan_Status is in the global namespace; this file is in CUScanner\Admin.
        $pages_payload = \AIAS_Scan_Status::build_pages( $pages_raw, $cu_json['by_page'] ?? [] );

        // B4 — stamp each page row with ratchet_recovered (int ≥ 0).
        // When the ratchet ran, $merger->recovered_by_pattern is keyed by url_pattern;
        // derive the same pattern for each page and look up the count.
        // When the ratchet did not run ($merger === null), all rows get 0.
        if ( null !== $merger ) {
            foreach ( $pages_payload as $idx => &$row ) {
                $pat = $merger->__test_url_to_pattern( (string) ( $pages_raw[ $idx ]['url'] ?? '' ) );
                $row['ratchet_recovered'] = (int) ( $merger->recovered_by_pattern[ $pat ] ?? 0 );
            }
            unset( $row );
        }

        $can_push      = ( new RulePusher() )->can_push();

        // Persist the full Step-4 restore payload (incl. the per-URL table + 12-char
        // scan_id) so a BACKGROUND-completed scan can rebuild the complete result screen
        // on operator return — get_badge_state() returns this verbatim. Field names match
        // the JS restore contract (scanner.js init). autoload=false (per-scan blob). Bug-fix (1.5.4).
        update_option( 'aias_last_result', [
            'job_id'        => $job_id,
            'safe_count'    => $safe_count,
            'agg_count'     => $agg_count,
            'can_push'      => $can_push,
            'external_only' => false,
            'total_pages'   => count( $pages_raw ),
            'scan_id'       => $scan_id_display,
            'pages'         => $pages_payload,
        ], false );

        return [
            'safe_count'       => $safe_count,
            'aggressive_count' => $agg_count,
            'can_push'         => $can_push,
            'scan_id'          => $scan_id_display,
            'pages_blocked'    => $pages_blocked,
            'blocked_reasons'  => $blocked_reasons,
            'total_pages'      => count( $pages_raw ),
            'pages'            => $pages_payload,
        ];
    }

    /**
     * FU-7 — handler for the SaaS-killed-by-admin terminal state arriving via
     * Railway's /jobs/:id/status response (status='killed'). Mirror of
     * cancel_job but without the Railway /cancel call (Railway already knows
     * — that's how we got the 'killed' status in the first place).
     *
     * The plugin's local ScanHistory record was created at reserve time with
     * status='in_progress'. Without this handler, scanner.js stops polling
     * but the local record stays at in_progress forever, so the History tab
     * shows the killed scan as still running. credits_used=0 (admin_kill is
     * non-charging on the SaaS side; the wpservice worker finalized with
     * source=admin_kill which charges 0).
     */
    public function handle_killed(): void {
        $this->check();
        $user_id = get_current_user_id();
        $state   = get_transient( 'cu_scanner_job_' . $user_id );

        // Even with no transient (e.g. session expired between Railway emitting
        // 'killed' and this handler being invoked), still try to clean up any
        // bypass tokens so the site doesn't sit in a half-bypassed state.
        ( new BypassManager() )->delete_all_tokens();

        if ( $state && ! empty( $state['job_id'] ) ) {
            ( new ScanHistory() )->update_status( $state['job_id'], 'cancelled', [
                'credits_used' => 0,  // admin_kill is non-charging
            ] );
            delete_transient( 'cu_scanner_job_' . $user_id );
        }

        wp_send_json_success();
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
        $raw    = sanitize_text_field( wp_unslash( $_GET['job_id'] ?? '' ) );
        $job_id = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $raw );
        if ( '' === $job_id ) { wp_die( 'Not found' ); }
        $json   = ( new ScanHistory() )->get_json( $job_id );
        if ( ! $json ) { wp_die( 'Not found' ); }
        header( 'Content-Type: application/json' );
        header( 'Content-Disposition: attachment; filename="cu-scanner-' . $job_id . '.json"' );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download served with Content-Disposition: attachment; not rendered as HTML.
        exit;
    }

    /**
     * Keep only rules whose url_pattern host equals the site home host (www.-stripped).
     * External rules are dropped. Shared by push_to_cu() and sync_to_cu().
     */
    private function filter_internal_rules( array $decoded ): array {
        $site_host = strtolower( preg_replace( '/^www\./i', '', wp_parse_url( get_home_url(), PHP_URL_HOST ) ?? '' ) );
        $decoded['rules'] = array_values( array_filter(
            $decoded['rules'] ?? [],
            function ( $rule ) use ( $site_host ) {
                $rule_host = strtolower( preg_replace( '/^www\./i', '', wp_parse_url( $rule['url_pattern'] ?? '', PHP_URL_HOST ) ?? '' ) );
                return $rule_host === $site_host;
            }
        ) );
        return $decoded;
    }

    public function push_to_cu(): void {
        $this->check();
        $job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        $json   = ( new ScanHistory() )->get_json( $job_id );
        if ( ! $json ) { wp_send_json_error( 'Scan data not found' ); return; }
        $pusher = new RulePusher();
        if ( ! $pusher->can_push() ) { wp_send_json_error( 'Code Unloader not active' ); return; }
        // Skip the overwrite confirm when CU has no active rules to overwrite (server-authoritative).
        $confirmed = ! empty( $_POST['confirmed'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        if ( ! $confirmed && $pusher->has_active_cu_rules() ) {
            wp_send_json_success( [ 'needs_confirm' => true ] );
            return;
        }
        try {
            $decoded = $this->filter_internal_rules( json_decode( $json, true ) );
            $summary = $pusher->push( $decoded );
            wp_send_json_success( $summary );
        } catch ( \Throwable $e ) {
            error_log( '[AI Assets Scanner] push_to_cu: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: exception detail is withheld from the browser and written to server error log only.
            wp_send_json_error( 'Push failed. Check server error logs.' );
        }
    }

    public function sync_to_cu(): void {
        $this->check();
        $job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->check() via check_ajax_referer().
        $json   = ( new ScanHistory() )->get_json( $job_id );
        if ( ! $json ) { wp_send_json_error( 'Scan data not found' ); return; }
        $pusher = new RulePusher();
        if ( ! $pusher->can_push() ) { wp_send_json_error( 'Code Unloader not active' ); return; }
        try {
            $decoded = $this->filter_internal_rules( json_decode( $json, true ) );
            if ( empty( $decoded['rules'] ) ) { wp_send_json_error( 'No internal rules to sync' ); return; }
            $summary = $pusher->sync( $decoded );
            wp_send_json_success( $summary );
        } catch ( \Throwable $e ) {
            error_log( '[AI Assets Scanner] sync_to_cu: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: detail withheld from browser, written to server error log only.
            wp_send_json_error( 'Sync failed. Check server error logs.' );
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

    /**
     * AJAX endpoint: probe external URLs for their actual optimizer stack.
     * Spec §6.1 + §6.1.1. Runs server-side wp_remote_get from operator's WP install
     * BEFORE cu_scanner_reserve_job — does NOT consume customer credit by construction.
     *
     * Request:  POST { action: cu_scanner_probe_target_stack, _wpnonce, urls: [string,...] }
     * Response: { success: true, data: { per_host_results, suggested_bypass_per_url, warning_needed, summary } }
     */
    public function probe_target_stack(): void {
        // AC-N2-Auth — nonce + capability. Uses '_wpnonce' (default WP form-nonce param)
        // to match the spec'd request shape; existing handlers use 'nonce' instead.
        if ( ! check_ajax_referer( 'cu_scanner_nonce', '_wpnonce', false ) ) {
            wp_send_json( [ 'ok' => false, 'error' => 'nonce_invalid' ], 403 );
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json( [ 'ok' => false, 'error' => 'permission_denied' ], 403 );
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
        $urls_raw = isset( $_POST['urls'] ) ? wp_unslash( $_POST['urls'] ) : [];
        if ( ! is_array( $urls_raw ) ) {
            wp_send_json( [ 'ok' => false, 'error' => 'urls_must_be_array' ], 400 );
            return;
        }
        $urls = array_values( array_filter( array_map(
            static fn( $u ) => esc_url_raw( (string) $u ),
            $urls_raw
        ) ) );

        // Group URLs by external host (per spec §6.1 server-side flow).
        $by_host = self::group_urls_by_host( $urls );

        // Per-host probe. PluginDetector::probe_target_stack handles its own 24h cache + 2-attempt fallback.
        // For each host, probe URL #1 with URL #2 as fallback (or root '/' if only one URL).
        $per_host_results = [];
        foreach ( $by_host as $host => $host_urls ) {
            $url1 = $host_urls[0];
            $url2 = $host_urls[1] ?? self::root_url_for( $host, $url1 );
            $result = \CUScanner\Scanner\PluginDetector::probe_target_stack( $url1, $url2, 12 );
            $result['host'] = $host;
            $per_host_results[] = $result;
        }

        // Build per-URL bypass map (§4.2 rule — every URL of same host gets same suffix list).
        $suggested_bypass_per_url = [];
        foreach ( $by_host as $host => $host_urls ) {
            $r = self::find_result_for_host( $per_host_results, $host );
            $bypass = is_array( $r['bypass_suffixes'] ?? null ) ? $r['bypass_suffixes'] : [];
            foreach ( $host_urls as $u ) {
                $suggested_bypass_per_url[ $u ] = $bypass;
            }
        }

        // AC-RC-8a — build per-URL resolved-URL map (mirrors $suggested_bypass_per_url).
        // PluginDetector::probe_target_stack returns resolved_url/submitted_url for the
        // exact URL it probed ($url1 per host). Resolution is URL-specific, so we honor
        // the probe's resolved_url ONLY for the matching submitted URL; every other URL
        // on that host (and any URL the probe didn't resolve) maps to itself (identity).
        // Built BEFORE strip_to_whitelist() below, which would otherwise drop these
        // non-whitelisted fields. JS threads this map back as submitted_urls[] on submit.
        $resolved_per_url = [];
        foreach ( $by_host as $host => $host_urls ) {
            $r = self::find_result_for_host( $per_host_results, $host );
            $probe_submitted = is_string( $r['submitted_url'] ?? null ) ? $r['submitted_url'] : '';
            $probe_resolved  = is_string( $r['resolved_url']  ?? null ) ? $r['resolved_url']  : '';
            foreach ( $host_urls as $u ) {
                // Identity default; override only for the exact URL the probe resolved.
                $resolved_per_url[ $u ] = ( $u === $probe_submitted && $probe_resolved !== '' )
                    ? $probe_resolved
                    : $u;
            }
        }

        // Determine warning_needed (any host has outcome other than class_a_clean).
        $warning_needed = false;
        foreach ( $per_host_results as $r ) {
            if ( ( $r['outcome'] ?? '' ) !== 'class_a_clean' ) {
                $warning_needed = true;
                break;
            }
        }

        // Strip non-whitelist fields from each per_host_results entry per AC-N2-SSRF (iii).
        $per_host_results = array_map( [ self::class, 'strip_to_whitelist' ], $per_host_results );

        wp_send_json( [
            'success' => true,
            'data'    => [
                'per_host_results'         => $per_host_results,
                'suggested_bypass_per_url' => $suggested_bypass_per_url,
                'resolved_per_url'         => $resolved_per_url,
                'warning_needed'           => $warning_needed,
                'summary'                  => [
                    'uniform_outcome'   => self::is_uniform_outcome( $per_host_results ),
                    'any_class_a_clean' => self::any_outcome_matches( $per_host_results, 'class_a_clean' ),
                ],
            ],
        ] );
    }

    /** Group input URLs by host (parsed via wp_parse_url). Strips www. prefix for consistent grouping. */
    private static function group_urls_by_host( array $urls ): array {
        $out = [];
        foreach ( $urls as $u ) {
            $host = wp_parse_url( $u, PHP_URL_HOST );
            if ( ! $host ) continue;
            $host = strtolower( preg_replace( '/^www\./i', '', $host ) );
            $out[ $host ][] = $u;
        }
        return $out;
    }

    /** Synthesize a root-URL fallback for hosts that only have 1 selected URL. */
    private static function root_url_for( string $host, string $reference_url ): string {
        $parts  = wp_parse_url( $reference_url );
        $scheme = $parts['scheme'] ?? 'https';
        return $scheme . '://' . $host . '/';
    }

    private static function find_result_for_host( array $results, string $host ): ?array {
        foreach ( $results as $r ) {
            if ( ( $r['host'] ?? null ) === $host ) return $r;
        }
        return null;
    }

    /**
     * AC-N2-SSRF (iii) — response field whitelist.
     * Drop any field not in the allowed list (defensive against probe_target_stack returning extras).
     */
    private static function strip_to_whitelist( array $r ): array {
        static $allowed = [ 'host','outcome','detected','bypass_suffixes','is_wordpress',
                            'probed_url_1','probed_url_2','probe_failed','probe_duration_ms',
                            'cache_hit','reason','protocol_downgrade' ];
        return array_intersect_key( $r, array_flip( $allowed ) );
    }

    private static function is_uniform_outcome( array $results ): bool {
        if ( empty( $results ) ) return true;
        $outcomes = array_unique( array_column( $results, 'outcome' ) );
        return count( $outcomes ) === 1;
    }

    private static function any_outcome_matches( array $results, string $outcome ): bool {
        foreach ( $results as $r ) {
            if ( ( $r['outcome'] ?? '' ) === $outcome ) return true;
        }
        return false;
    }

    /**
     * FU-NEW-2 Phase 5 — Build the pages[] array for submit_job per spec §4.2 rule.
     *
     * - Internal URLs (same host as $home_url) use $host_bypass (today's behavior).
     * - External URLs use $target_bypass_per_url[url] ?? [] (empty default; NEVER host-leaked).
     *   When fallback fires, do_action('cu_scanner_target_bypass_missing', [...]) telemetry hook.
     *
     * Note: bypass_token is attached downstream where the token is built — this helper
     * focuses solely on the per-URL bypass_suffixes decision (the load-bearing §4.2 rule).
     *
     * @param string[]            $selected_urls         Raw selected URLs (already resolved — the URL we scan).
     * @param string[]            $host_bypass           Host-detected bypass suffixes (today's array).
     * @param array<string,array> $target_bypass_per_url Per-URL bypass map from probe response (keyed by URL).
     * @param string              $home_url              Site's home URL (for internal/external classification).
     * @param array<string,mixed> $et_set                FU-AAS-EXTRA-TIME — array_flip'd membership set of Extra-Time URLs.
     * @param array<string,string> $submitted_url_per_url AC-RC-8a — resolved-URL → original-submitted-URL map.
     *                                                     Mirrors $target_bypass_per_url: keyed by the (resolved)
     *                                                     scan URL, defaults to the URL itself when absent.
     * @return array<int,array{url:string,bypass_suffixes:array,extra_time:bool,submitted_url:string}>
     */
    private static function build_pages_array( array $selected_urls, array $host_bypass,
                                                array $target_bypass_per_url, string $home_url,
                                                array $et_set = [], array $submitted_url_per_url = [] ): array {
        $home_host = strtolower( preg_replace( '/^www\./i', '',
            wp_parse_url( $home_url, PHP_URL_HOST ) ?: '' ) );
        $pages = [];
        foreach ( $selected_urls as $url ) {
            $host = strtolower( preg_replace( '/^www\./i', '',
                wp_parse_url( $url, PHP_URL_HOST ) ?: '' ) );
            $is_external = ( $host !== '' && $host !== $home_host );

            if ( $is_external ) {
                if ( isset( $target_bypass_per_url[ $url ] ) ) {
                    $bypass_suffixes = $target_bypass_per_url[ $url ];
                } else {
                    // AC-N2-12 — external URL missing from map → empty fallback + telemetry.
                    $bypass_suffixes = [];
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'cu_scanner_*' is the long-standing internal prefix shared with the wpservice-saas backend and the Railway worker; renaming would break inter-component contracts.
                    do_action( 'cu_scanner_target_bypass_missing', [ 'url' => $url, 'host' => $host ] );
                }
            } else {
                $bypass_suffixes = $host_bypass;
            }

            $pages[] = [
                'url'             => $url,
                'bypass_suffixes' => $bypass_suffixes,
                // FU-AAS-EXTRA-TIME — flag this page for Extra Time if the operator
                // marked its URL. Carried through reshape_page_specs() into pages[].
                'extra_time'      => isset( $et_set[ $url ] ),
                // AC-RC-8a — original operator-submitted URL (pre-redirect-resolution).
                // $url here is the RESOLVED scan URL; submitted_url preserves what the
                // operator actually entered so downstream attribution stays honest.
                // Defaults to $url when the probe found no redirect (identity).
                'submitted_url'   => $submitted_url_per_url[ $url ] ?? $url,
                // bypass_token attached downstream where the token is built.
            ];
        }
        return $pages;
    }

    /**
     * Reshape page specs (from build_pages_array) into the final pages[] payload
     * sent to RailwayClient::submit_job. This is the hardcoded-key seam: any key
     * NOT named here is silently dropped, so FU-AAS-EXTRA-TIME's extra_time flag
     * must be carried through explicitly.
     *
     * @param array<int,array{url:string,bypass_suffixes:array,extra_time?:bool,submitted_url?:string}> $page_specs
     * @param callable $build_scan_url fn( string $url, array $bypass_suffixes ): string
     * @param string   $token          Bypass token attached to every page.
     * @return array<int,array{url:string,bypass_token:string,bypass_suffixes:array,extra_time:bool,submitted_url:string}>
     */
    private static function reshape_page_specs( array $page_specs, callable $build_scan_url, string $token ): array {
        return array_map(
            static fn( array $spec ): array => [
                'url'             => $build_scan_url( $spec['url'], $spec['bypass_suffixes'] ),
                'bypass_token'    => $token,
                'bypass_suffixes' => $spec['bypass_suffixes'],
                'extra_time'      => $spec['extra_time'] ?? false,
                // AC-RC-8a — original submitted URL. This is the hardcoded-key seam:
                // any key not named here is silently dropped, so submitted_url must be
                // carried through explicitly. Falls back to the (resolved) url.
                'submitted_url'   => $spec['submitted_url'] ?? $spec['url'],
            ],
            $page_specs
        );
    }

    /**
     * FU-NEW-2 Phase 5 — Capture target_stack_summary from POST data (forwarded by JS after probe).
     * Returns null if absent/empty (caller omits the field from SaaS payload).
     * Filters non-conforming entries (missing host or outcome).
     *
     * @param mixed $post_value Raw $_POST['target_stack_summary'] value.
     * @return array<int,array{host:string,detected:array,outcome:string,cache_hit:bool}>|null
     */
    private static function capture_target_stack_summary( $post_value ): ?array {
        if ( ! is_array( $post_value ) || empty( $post_value ) ) return null;
        $out = [];
        foreach ( $post_value as $entry ) {
            if ( ! is_array( $entry ) ) continue;
            if ( empty( $entry['host'] ) || empty( $entry['outcome'] ) ) continue;
            $out[] = [
                'host'      => sanitize_text_field( (string) $entry['host'] ),
                // FU-TSS-DETECTED — the probe sends each detected entry as an object
                // {name,class,bypass_query,source}; extract the optimizer name (tolerating
                // legacy string entries). Casting the object via (string) corrupted it to
                // "Array" + emitted a PHP warning. Per WP-compliance #27, every leaf of this
                // untrusted $_POST map is validated (drop nameless entries) + text-sanitized.
                'detected'  => isset( $entry['detected'] ) && is_array( $entry['detected'] )
                    ? array_values( array_filter( array_map(
                        static function ( $d ) {
                            $name = is_array( $d ) ? ( $d['name'] ?? '' ) : $d;
                            return sanitize_text_field( (string) $name );
                        },
                        $entry['detected']
                    ), static fn( $n ) => '' !== $n ) )
                    : [],
                'outcome'   => sanitize_text_field( (string) $entry['outcome'] ),
                'cache_hit' => ! empty( $entry['cache_hit'] ),
            ];
        }
        return empty( $out ) ? null : $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // ET Result Ratchet helpers (B2 + B3)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * True iff any page in $pages_raw has `extra_time_charged` truthy.
     * ET is per-page, not per-scan; treat the whole scan as an ET rescan
     * if at least one page consumed an ET continuation.
     *
     * @param array $pages_raw Per-page Railway result rows.
     * @return bool
     */
    private function is_et_rescan( array $pages_raw ): bool {
        foreach ( $pages_raw as $page ) {
            if ( ! empty( $page['extra_time_charged'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * B2 — Persist R_orig (per-rule identity keys + scanned URL set) to a
     * user-scoped transient so a subsequent ET rescan can merge against it.
     * Called only on non-ET scans, after CuJsonBuilder::build() returns.
     *
     * @param array $cu_json   Built CU JSON (rules + by_page).
     * @param array $pages_raw Per-page Railway result rows (for URL set).
     */
    private function persist_r_orig( array $cu_json, array $pages_raw ): void {
        $keys = [];
        foreach ( $cu_json['rules'] as $r ) {
            $keys[] = [
                'url_pattern'  => $r['url_pattern'],
                'asset_handle' => $r['asset_handle'],
                'asset_type'   => $r['asset_type'],
                'device_type'  => $r['device_type'],
                'group_id'     => $r['group_id'],
            ];
        }
        $urls = array_values( array_unique( array_column( $pages_raw, 'url' ) ) );
        set_transient(
            'cu_scanner_r_orig_' . get_current_user_id(),
            [ 'urls' => $urls, 'rules' => $keys ],
            HOUR_IN_SECONDS
        );
    }

    /**
     * AC-ETR-9 — staleness guard: the transient is valid and covers the same
     * URL set as the current ET rescan.
     *
     * @param mixed $r_orig     Value returned by get_transient().
     * @param array $pages_raw  Per-page Railway result rows for this rescan.
     * @return bool True iff $r_orig is usable for the merge.
     */
    private function r_orig_matches( $r_orig, array $pages_raw ): bool {
        if ( ! is_array( $r_orig ) || empty( $r_orig['rules'] ) || ! isset( $r_orig['urls'] ) ) {
            return false;
        }
        $orig_url_set = array_flip( $r_orig['urls'] );
        foreach ( array_column( $pages_raw, 'url' ) as $url ) {
            if ( ! isset( $orig_url_set[ $url ] ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Recompute the by_page tally from merged rules so the Step-4 S/A/N
     * counts reflect the post-merge rule set.
     *
     * Invariant: array_sum(column safe) === count(rules group_id 1),
     *            array_sum(column aggressive) === count(rules group_id 2).
     *
     * Strategy: derive each page's url_pattern the same way CuJsonBuilder
     * does (via RatchetMerger's __test_url_to_pattern, which is byte-identical),
     * then group merged rules by url_pattern and count per page.
     * `needed` is preserved from $orig_by_page — recomputing it would require
     * re-walking the full asset list which is not available here, and the spec
     * permits preserving the original needed count.
     *
     * @param array $rules        Merged CU rule array (recollapsed).
     * @param array $pages_raw    Per-page Railway result rows (index-aligned with by_page).
     * @param array $orig_by_page by_page from the pre-merge CuJsonBuilder::build() output.
     * @return array New by_page array keyed by original page index.
     */
    private function recompute_by_page( array $rules, array $pages_raw, array $orig_by_page ): array {
        // Build a count map: url_pattern → [safe => N, aggressive => M].
        $merger   = new \CUScanner\Scanner\RatchetMerger();
        $rule_map = [];
        foreach ( $rules as $r ) {
            $pat = $r['url_pattern'];
            if ( ! isset( $rule_map[ $pat ] ) ) {
                $rule_map[ $pat ] = [ 'safe' => 0, 'aggressive' => 0 ];
            }
            if ( 1 === $r['group_id'] ) {
                $rule_map[ $pat ]['safe']++;
            } else {
                $rule_map[ $pat ]['aggressive']++;
            }
        }

        // Walk pages_raw by index; derive pattern per page; look up counts.
        $by_page = [];
        foreach ( $pages_raw as $i => $page ) {
            $pat    = $merger->__test_url_to_pattern( $page['url'] ?? '' );
            $safe   = $rule_map[ $pat ]['safe']       ?? 0;
            $agg    = $rule_map[ $pat ]['aggressive'] ?? 0;
            // Preserve original needed count — not affected by merge.
            $needed = $orig_by_page[ $i ]['needed'] ?? 0;
            $by_page[ $i ] = [ 'safe' => $safe, 'aggressive' => $agg, 'needed' => $needed ];
        }
        return $by_page;
    }

    // --- Test seams (public; call into private helpers for unit testing) ---
    public function __test_ratchet_enabled(): bool { return $this->ratchet_enabled(); }
    public function __test_is_et_rescan( array $pages_raw ): bool { return $this->is_et_rescan( $pages_raw ); }
    public function __test_persist_r_orig( array $cu_json, array $pages_raw ): void { $this->persist_r_orig( $cu_json, $pages_raw ); }
    public function __test_should_persist_r_orig( array $pages_raw ): bool { return $this->ratchet_enabled() && ! $this->is_et_rescan( $pages_raw ); }
    public function __test_r_orig_matches( $r_orig, array $pages_raw ): bool { return $this->r_orig_matches( $r_orig, $pages_raw ); }
    public function __test_recompute_by_page( array $rules, array $pages_raw, array $orig_by_page ): array { return $this->recompute_by_page( $rules, $pages_raw, $orig_by_page ); }

    /**
     * B4 test seam: exposes the ratchet_recovered stamp loop so tests can exercise
     * it in isolation without triggering do_build_result's Railway I/O.
     *
     * @param array                                       $pages_payload Pages payload rows (by reference internally; returns stamped copy).
     * @param array                                       $pages_raw     Raw Railway pages (used for url→pattern derivation).
     * @param \CUScanner\Scanner\RatchetMerger|null       $merger        Merger instance after merge(), or null if ratchet did not run.
     * @return array Stamped pages_payload.
     */
    public function __test_inject_ratchet_recovered( array $pages_payload, array $pages_raw, ?\CUScanner\Scanner\RatchetMerger $merger ): array {
        if ( null !== $merger ) {
            foreach ( $pages_payload as $idx => &$row ) {
                $pat = $merger->__test_url_to_pattern( (string) ( $pages_raw[ $idx ]['url'] ?? '' ) );
                $row['ratchet_recovered'] = (int) ( $merger->recovered_by_pattern[ $pat ] ?? 0 );
            }
            unset( $row );
        }
        return $pages_payload;
    }

    // --- Test seams (public static; call into private static helpers for unit testing) ---
    public static function __test_group_urls_by_host( array $urls ): array {
        return self::group_urls_by_host( $urls );
    }
    public static function __test_strip_to_whitelist( array $r ): array {
        return self::strip_to_whitelist( $r );
    }
    public static function __test_build_pages_array( array $selected_urls, array $host_bypass,
                                                      array $target_bypass_per_url, string $home_url,
                                                      array $et_set = [], array $submitted_url_per_url = [] ): array {
        return self::build_pages_array( $selected_urls, $host_bypass, $target_bypass_per_url, $home_url, $et_set, $submitted_url_per_url );
    }
    public static function __test_reshape_page_specs( array $page_specs, callable $build_scan_url, string $token ): array {
        return self::reshape_page_specs( $page_specs, $build_scan_url, $token );
    }
    public static function __test_capture_target_stack_summary( $post_value ): ?array {
        return self::capture_target_stack_summary( $post_value );
    }

    /**
     * 1.4.10 — browser-driven badge state poll.
     *
     * Backs the setInterval poller in admin/js/menu-badge.js. Each tick (~30s):
     *   1. Calls MenuBadge::run_polling_check_and_get_state() — drives the
     *      same Railway poll + transient + ScanHistory update logic as the
     *      1.4.9 admin_init path.
     *   2. Returns the resulting badge state ('green' | 'red' | null) so the
     *      JS can sync the DOM badge node independently of operator navigation.
     *
     * Independent of WP Heartbeat (the 1.4.8-diag investigation proved
     * heartbeat_received is bypassed on operator's WP install) and independent
     * of admin_init (the 1.4.9 attempt that depends on operator navigation
     * firing fresh admin requests — fails when operator sits idle on one page
     * during the scan-end transition window).
     */
    public function get_badge_state(): void {
        $this->check();
        $state = ( new \CUScanner\MenuBadge() )->run_polling_check_and_get_state();

        // 1.4.11 — also return a `result` snapshot when state is 'green' so the
        // JS poller can populate cu_scanner_result in localStorage. Without
        // this the badge appears but operator-returning-to-AAS sees the default
        // Step 1 screen because no localStorage entry exists (scanner.js init
        // at admin/js/scanner.js:1349 reads localStorage to restore Step 4,
        // and the 1.4.10 server-side build_result path doesn't write it).
        // 1.5.4 — return the full restore payload persisted by do_build_result()
        // (incl. the per-URL `pages` table + 12-char scan_id) so operator-returning-to-AAS
        // rebuilds the COMPLETE Step-4 screen, not just summary counts. The JS poller
        // writes res.data.result verbatim to localStorage (menu-badge.js).
        $result = null;
        if ( $state === 'green' ) {
            $stored = get_option( 'aias_last_result', null );
            if ( is_array( $stored ) && ! empty( $stored['job_id'] ) ) {
                $result = $stored;
            }
        }

        wp_send_json_success( [ 'badge' => $state, 'result' => $result ] );
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

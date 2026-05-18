<?php
namespace CUScanner;

defined( 'ABSPATH' ) || exit;

class MenuBadge {

    private const OPTION_LAST_SEEN = 'aias_last_seen_scan_id';

    // Badge state values (returned by get_badge_state + on Heartbeat wire).
    private const BADGE_STATE_GREEN = 'green';
    private const BADGE_STATE_RED   = 'red';

    // Production status strings — verified against admin/class-scanner-ajax.php writes.
    // 'queued' L387 (initial, non-terminal) | 'cancelled' L507+L650 (terminal, non-triggering)
    // 'complete' L547 (success → green) | 'failed' L687 (error → red)
    private const STATUS_COMPLETE  = 'complete';
    private const STATUS_FAILED    = 'failed';
    private const STATUS_CANCELLED = 'cancelled';

    /** @var ScanHistory|null Constructor-injected for testability (per d-review Minor 5). */
    private $history;

    public function __construct( ?ScanHistory $history = null ) {
        $this->history = $history;   // null => lazy-init in get_history() (production path).
    }

    /**
     * Determine current badge state.
     * Returns 'red' if most-recent badge-triggering terminal scan is failed-and-unseen.
     * Returns 'green' if most-recent badge-triggering terminal scan is complete-and-unseen.
     * Returns null if no badge-triggering terminal scan exists, or it has been seen.
     *
     * "Badge-triggering terminal" = status in {'complete', 'failed'}.
     * Excludes 'queued' (not terminal) and 'cancelled' (terminal but operator-dismissed).
     */
    public function get_badge_state(): ?string {
        $latest_rec = $this->most_recent_triggering_record( $this->get_history()->get_all() );
        if ( $latest_rec === null ) {
            return null;
        }

        $latest_seen = (string) get_option( self::OPTION_LAST_SEEN, '' );
        if ( $latest_rec['job_id'] === $latest_seen ) {
            return null;
        }

        return $latest_rec['status'] === self::STATUS_FAILED
            ? self::BADGE_STATE_RED
            : self::BADGE_STATE_GREEN;
    }

    /**
     * Mark the most-recent badge-triggering terminal scan as seen.
     * Called when operator lands on AAS main scanner page (admin_head hook).
     *
     * Conditional update_option per d-review Minor 6: skip the write when the
     * option already matches the latest job_id. WP-core's update_option short-
     * circuits identical values internally, but the explicit guard removes the
     * SELECT round-trip too.
     */
    public function mark_seen_on_main_page(): void {
        $latest_rec = $this->most_recent_triggering_record( $this->get_history()->get_all() );
        if ( $latest_rec === null ) {
            return;
        }

        $current = (string) get_option( self::OPTION_LAST_SEEN, '' );
        if ( $current === $latest_rec['job_id'] ) {
            return;
        }

        update_option( self::OPTION_LAST_SEEN, $latest_rec['job_id'] );
    }

    public function init(): void {
        // 1.4.8-diag — log every init() call (proves MenuBadge is instantiated +
        // hooks registered for THIS request context). If this entry is absent
        // from debug.log during heartbeat AJAX windows, AdminPages::register()
        // isn't being called for AJAX requests → likely is_admin() returning
        // false in that context, or plugins_loaded chain broken.
        error_log( '[AI Assets Scanner] MenuBadge::init fired (is_admin=' . ( is_admin() ? '1' : '0' ) . ' doing_ajax=' . ( wp_doing_ajax() ? '1' : '0' ) . ' user=' . get_current_user_id() . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging.

        add_filter( 'add_menu_classes',                    [ $this, 'filter_menu_title' ],     10, 1 );
        add_filter( 'heartbeat_received',                  [ $this, 'filter_heartbeat' ],      10, 2 );
        add_action( 'admin_head-toplevel_page_cu-scanner', [ $this, 'mark_seen_on_main_page' ] );
        add_action( 'admin_print_styles',                  [ $this, 'print_inline_css' ] );
        add_action( 'admin_enqueue_scripts',               [ $this, 'enqueue_heartbeat_listener' ] );

        // 1.4.9 — server-side polling via admin_init instead of heartbeat_received.
        // The heartbeat_received filter is bypassed on this WordPress install
        // (diagnostic confirmed via 1.4.8-diag: init() + filter_menu_title fire
        // correctly but filter_heartbeat NEVER fires — another plugin's
        // wp_ajax_heartbeat override is short-circuiting the apply_filters chain).
        // admin_init is a WP-core hook fired on every admin request (including
        // admin-ajax.php), much harder to bypass. Rate-limited to ≥15s between
        // polls via a transient so we don't hammer Railway when admin_init fires
        // many times per second on AJAX-heavy pages.
        add_action( 'admin_init', [ $this, 'maybe_poll_active_job_on_admin_init' ], 99 );
    }

    /**
     * 1.4.9 — rate-limited server-side polling driven by admin_init.
     *
     * admin_init fires on every admin request — regular page renders + AJAX +
     * Heartbeat. Without rate limiting, this would poll Railway dozens of times
     * per minute. The transient `aias_menu_badge_last_poll` caps polling to
     * once per 15 seconds (matching the previous Heartbeat-driven cadence).
     */
    public function maybe_poll_active_job_on_admin_init(): void {
        $last_poll = (int) get_transient( 'aias_menu_badge_last_poll' );
        if ( $last_poll + 15 > time() ) {
            return;
        }
        // Set first to avoid races where two near-simultaneous requests both
        // pass the rate-limit check + double-poll.
        set_transient( 'aias_menu_badge_last_poll', time(), 60 );

        $this->check_active_job_completion();
    }

    /**
     * 1.4.10 — public entry point for the browser-driven setInterval poller in
     * menu-badge.js. The 1.4.9 admin_init path works but depends on operator
     * navigation: if the operator sits idle on one admin page, admin_init
     * doesn't fire and the badge transition is missed. The setInterval in
     * menu-badge.js fires every 30s independent of operator navigation, hits
     * cu_scanner_get_badge_state, which calls THIS method to drive the same
     * check_active_job_completion path. Returns the post-check badge state so
     * the AJAX response can carry it straight to the JS DOM updater.
     */
    public function run_polling_check_and_get_state(): ?string {
        $this->check_active_job_completion();
        return $this->get_badge_state();
    }

    /**
     * WordPress passes the global $menu array (each item is [ $menu_title,
     * $capability, $menu_slug, $page_title, $css_class, $hookname, $icon_url ]).
     * We match by $menu_slug at index 2 ('cu-scanner') and append a badge span
     * to the title HTML if the badge state is non-null.
     */
    public function filter_menu_title( $menu ) {
        // 1.4.8-diag — log every filter_menu_title call. If this fires but
        // filter_heartbeat doesn't, the WP-core heartbeat handler is being
        // bypassed by another plugin (Heartbeat Control, Wordfence, WP Rocket,
        // etc. replacing wp_ajax_heartbeat with a custom handler that doesn't
        // call apply_filters('heartbeat_received', ...)).
        error_log( '[AI Assets Scanner] filter_menu_title fired (doing_ajax=' . ( wp_doing_ajax() ? '1' : '0' ) . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging.

        $state = $this->get_badge_state();
        if ( $state === null ) {
            return $menu;
        }

        foreach ( $menu as $position => $item ) {
            if ( isset( $item[2] ) && $item[2] === 'cu-scanner' ) {
                $menu[ $position ][0] = $item[0] . ' ' . $this->badge_html( $state );
                break;
            }
        }
        return $menu;
    }

    /**
     * Heartbeat hook — runs ~every 15s on any wp-admin page.
     * Returns the current badge state in the response so JS can update the DOM.
     *
     * Wire shape: PHP null → JSON null (preserved across heartbeat AJAX
     * response). JS uses hasOwnProperty.call(response, 'aias_badge') to
     * distinguish "key absent" from "key present, null". Both paths handled.
     */
    public function filter_heartbeat( array $response, array $data ): array {
        // 1.4.8-diag — log every filter_heartbeat call. If this fires, the
        // heartbeat_received hook is being applied and my callback is in the
        // chain. Combined with init()/filter_menu_title logs, distinguishes:
        // (a) hook not registered, (b) hook bypassed by another plugin's
        // wp_ajax_heartbeat override, (c) hook fires but check_active_job
        // early-returns.
        error_log( '[AI Assets Scanner] filter_heartbeat fired (user=' . get_current_user_id() . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging.

        // 1.4.5 — server-side background scan-completion polling. Closes the
        // 1.4.4 client-side-only architectural gap where menu-badge.js's polling
        // proved unreliable (zero cu_scanner_build_result calls observed in
        // production despite the Heartbeat ticks firing). The server-side path
        // runs in filter_heartbeat (already executing on every wp-admin page
        // every ~15s), polls Railway via wp_remote_get (no CORS, no tab-state
        // dependency), and triggers the existing build_result logic when the
        // scan reaches a terminal state.
        $this->check_active_job_completion();

        $response['aias_badge'] = $this->get_badge_state();   // 'green' | 'red' | null
        return $response;
    }

    /**
     * 1.4.5 — server-side scan-completion polling driven by WP Heartbeat.
     *
     * Reads the `cu_scanner_job_<user_id>` transient (set by ScannerAjax::submit_job
     * at L389-394 with {job_id, job_token, bypass_token, railway_url}). If present,
     * fetches Railway's job status via the existing RailwayClient::get_status. On
     * terminal status:
     *   - 'complete' → calls ScannerAjax::do_build_result (the 1.4.5-refactored
     *     callable extracted from the AJAX handler); on failure, force-fails the
     *     scan + deletes the transient to break the poll loop.
     *   - 'failed' → updates ScanHistory + deletes the transient.
     *   - 'killed' / 'cancelled_timeout' → deletes the transient (the kill path
     *     was already handled by the orchestrator that issued the kill).
     *   - 'queued' / 'in_progress' / anything else → no-op, next heartbeat tick re-polls.
     *
     * Idempotent: when scanner.js also fires build_result (e.g., operator on AAS),
     * the second call re-writes the same 'complete' record harmlessly. The transient
     * is deleted on the first successful build_result so subsequent ticks early-return.
     */
    private function check_active_job_completion(): void {
        $user_id = get_current_user_id();
        $transient_key = 'cu_scanner_job_' . $user_id;
        $state = get_transient( $transient_key );

        // 1.4.7-diag — log EVERY heartbeat tick BEFORE the early-return check so
        // we can distinguish "filter_heartbeat not firing" (zero entries) from
        // "transient missing" (entries with state=false/null). Was logging AFTER
        // is_array check in 1.4.6, which produced zero entries in both failure
        // modes. Spam bounded — operator enables WP_DEBUG_LOG only during active
        // diagnostic windows.
        $state_diag = is_array( $state ) ? 'array(' . count( $state ) . ')' : var_export( $state, true );
        error_log( '[AI Assets Scanner] menu-badge tick: user=' . $user_id . ' transient=' . $state_diag ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging.

        if ( ! is_array( $state ) ) {
            // No active scan — early return silently.
            return;
        }

        $job_id      = (string) ( $state['job_id']      ?? '' );
        $job_token   = (string) ( $state['job_token']   ?? '' );
        $railway_url = (string) ( $state['railway_url'] ?? '' );
        if ( $job_id === '' || $job_token === '' || $railway_url === '' ) {
            error_log( '[AI Assets Scanner] menu-badge tick: transient malformed (missing job_id/token/railway_url)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging.
            return;
        }

        try {
            $client = new \CUScanner\Api\RailwayClient( $railway_url, ( new \CUScanner\Settings() )->get_api_key() );
            $status = $client->get_status( $job_id, $job_token, 0 );
        } catch ( \RuntimeException $e ) {
            error_log( '[AI Assets Scanner] menu-badge heartbeat poll FAILED: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging: Railway exception detail captured server-side only.
            return;
        }

        $rs = (string) ( $status['status'] ?? '' );
        error_log( '[AI Assets Scanner] menu-badge tick: Railway status=' . $rs . ' job=' . $job_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging.

        if ( $rs === 'complete' ) {
            error_log( '[AI Assets Scanner] menu-badge: firing do_build_result for job=' . $job_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging.
            try {
                ( new \CUScanner\Admin\ScannerAjax() )->do_build_result( $job_id, $job_token );
                error_log( '[AI Assets Scanner] menu-badge: do_build_result OK for job=' . $job_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging.
            } catch ( \RuntimeException $e ) {
                // Build failed (e.g., Railway 410 — job data expired between status
                // poll and coverage fetch). Force the scan into 'failed' state so the
                // badge fires red AND the transient is deleted to break the poll loop.
                error_log( '[AI Assets Scanner] menu-badge build_result FAILED: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging: forced-failure path.
                $this->get_history()->update_status( $job_id, 'failed' );
                delete_transient( $transient_key );
            }
            // do_build_result deletes the transient itself on success (scanner-ajax.php:559).
        } elseif ( $rs === 'failed' ) {
            error_log( '[AI Assets Scanner] menu-badge: marking failed for job=' . $job_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging.
            $this->get_history()->update_status( $job_id, 'failed' );
            delete_transient( $transient_key );
        } elseif ( $rs === 'killed' || $rs === 'cancelled_timeout' ) {
            // Kill paths are already terminal in ScanHistory via the orchestrator
            // (ScannerAjax::handle_killed / cancel paths). Just clear the transient
            // so the polling loop stops; status is already correctly recorded.
            error_log( '[AI Assets Scanner] menu-badge: clearing transient for ' . $rs . ' job=' . $job_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic logging.
            delete_transient( $transient_key );
        }
        // 'queued' / 'in_progress' / unknown → no-op; next heartbeat tick re-polls.
    }

    /**
     * Inline CSS for the badge. Emitted unconditionally on every wp-admin page.
     *
     * Why not gated on get_badge_state() !== null: the Heartbeat path can
     * transition state null → green/red WITHOUT a page reload; the JS would
     * then inject a <span> with no CSS to style it. Emitting ~200 bytes of
     * inline CSS on every admin page is the cheaper trade-off.
     */
    public function print_inline_css(): void {
        echo '<style id="aias-menu-badge-css">'
            . '.aias-menu-badge { display:inline-block; margin-left:6px; padding:1px 7px; '
            . 'border-radius:10px; color:#fff; font-weight:bold; font-size:11px; '
            . 'line-height:17px; vertical-align:middle; }'
            . '.aias-menu-badge--green { background:#46b450; }'
            . '.aias-menu-badge--red   { background:#dc3232; }'
            . '</style>';
    }

    public function enqueue_heartbeat_listener(): void {
        wp_enqueue_script(
            'aias-menu-badge',
            CU_SCANNER_URL . 'admin/js/menu-badge.js',
            [ 'jquery', 'heartbeat' ],
            CU_SCANNER_VERSION,
            true
        );
        // 1.4.4 — localize ajaxurl + nonce for the background active-job poller
        // in menu-badge.js. Nonce action matches the existing cu_scanner_nonce
        // used by scanner.js + ScannerAjax::check() (admin/class-scanner-ajax.php:42).
        wp_localize_script( 'aias-menu-badge', 'aiasMenuBadgeData', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'cu_scanner_nonce' ),
        ] );
    }

    /**
     * Returns the most-recent BADGE-TRIGGERING terminal record, walking newest-first.
     * 'complete' and 'failed' trigger the badge; 'cancelled' and 'queued' are skipped.
     */
    private function most_recent_triggering_record( array $history ): ?array {
        foreach ( $history as $rec ) {
            $status = $rec['status'] ?? '';
            if ( $status === self::STATUS_COMPLETE || $status === self::STATUS_FAILED ) {
                return $rec;
            }
        }
        return null;
    }

    private function get_history(): ScanHistory {
        if ( $this->history === null ) {
            $this->history = new ScanHistory();
        }
        return $this->history;
    }

    private function badge_html( string $state ): string {
        $cls = esc_attr( 'aias-menu-badge aias-menu-badge--' . $state );
        return '<span class="' . $cls . '" aria-label="Unseen scan result">!</span>';
    }
}

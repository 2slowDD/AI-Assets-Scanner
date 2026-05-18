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
        add_filter( 'add_menu_classes',                    [ $this, 'filter_menu_title' ],     10, 1 );
        add_filter( 'heartbeat_received',                  [ $this, 'filter_heartbeat' ],      10, 2 );
        add_action( 'admin_head-toplevel_page_cu-scanner', [ $this, 'mark_seen_on_main_page' ] );
        add_action( 'admin_print_styles',                  [ $this, 'print_inline_css' ] );
        add_action( 'admin_enqueue_scripts',               [ $this, 'enqueue_heartbeat_listener' ] );
    }

    /**
     * WordPress passes the global $menu array (each item is [ $menu_title,
     * $capability, $menu_slug, $page_title, $css_class, $hookname, $icon_url ]).
     * We match by $menu_slug at index 2 ('cu-scanner') and append a badge span
     * to the title HTML if the badge state is non-null.
     */
    public function filter_menu_title( $menu ) {
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
        $response['aias_badge'] = $this->get_badge_state();   // 'green' | 'red' | null
        return $response;
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

<?php
namespace CUScanner\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

class AdminPages {
    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_notices', [ $this, 'maybe_render_history_deleted_notice' ] );
        ( new \CUScanner\MenuBadge() )->init();
    }

    public function add_menus(): void {
        $icon = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgZmlsbD0ibm9uZSI+PGNpcmNsZSBjeD0iMTAiIGN5PSIxMCIgcj0iOC41IiBzdHJva2U9IiM3MmFlZTYiIHN0cm9rZS13aWR0aD0iMS4yIiBvcGFjaXR5PSIwLjMiLz48Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSI1LjUiIHN0cm9rZT0iIzcyYWVlNiIgc3Ryb2tlLXdpZHRoPSIxLjIiIG9wYWNpdHk9IjAuNTUiLz48Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSIyLjgiIHN0cm9rZT0iIzcyYWVlNiIgc3Ryb2tlLXdpZHRoPSIxLjIiIG9wYWNpdHk9IjAuODUiLz48Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSIxIiBmaWxsPSIjNzJhZWU2Ii8+PGxpbmUgeDE9IjEwIiB5MT0iMTAiIHgyPSIxNi41IiB5Mj0iMy41IiBzdHJva2U9IiM3MmFlZTYiIHN0cm9rZS13aWR0aD0iMS4yIiBzdHJva2UtbGluZWNhcD0icm91bmQiLz48L3N2Zz4=';
        add_menu_page(
            'AI Assets Scanner', 'AI Assets Scanner', 'manage_options',
            'cu-scanner', [ $this, 'render_scanner' ],
            $icon, 80
        );
        add_submenu_page(
            'cu-scanner', 'Settings', 'Settings', 'manage_options',
            'cu-scanner-settings', [ $this, 'render_settings' ]
        );
        add_submenu_page(
            'cu-scanner', 'Scan History', 'Scan History', 'manage_options',
            'cu-scanner-history', [ $this, 'render_history' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        $pages = [ 'toplevel_page_cu-scanner', 'ai-assets-scanner_page_cu-scanner-settings', 'ai-assets-scanner_page_cu-scanner-history' ];
        if ( ! in_array( $hook, $pages, true ) ) return;
        wp_enqueue_style( 'cu-scanner-admin', CU_SCANNER_URL . 'admin/css/ai-assets-scanner-admin.css', [], CU_SCANNER_VERSION );
        if ( $hook === 'toplevel_page_cu-scanner' ) {
            wp_enqueue_script( 'cu-scanner-scanner', CU_SCANNER_URL . 'admin/js/scanner.js', [], CU_SCANNER_VERSION, true );
            wp_localize_script( 'cu-scanner-scanner', 'cuScanner', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'cu_scanner_nonce' ),
                'siteUrl' => get_home_url(),
                'outbox'  => \CUScanner\Scanner\Outbox::outbox_state_for_user( get_current_user_id() ),
            ] );
            // Subsystem D-4: nonce for AJAX banner-dismiss endpoint.
            wp_localize_script( 'cu-scanner-scanner', 'aiasBannerL10n', [
                'nonce' => wp_create_nonce( 'aias_dismiss_banner' ),
            ] );
        }
        if ( $hook === 'ai-assets-scanner_page_cu-scanner-settings' ) {
            wp_enqueue_script( 'cu-scanner-settings', CU_SCANNER_URL . 'admin/js/settings.js', [], CU_SCANNER_VERSION, true );
            wp_localize_script( 'cu-scanner-settings', 'cuScannerSettings', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'cu_scanner_settings_nonce' ),
            ] );
        }
        if ( $hook === 'ai-assets-scanner_page_cu-scanner-history' ) {
            wp_enqueue_script(
                'cu-scanner-history',
                CU_SCANNER_URL . 'admin/js/history.js',
                [ 'jquery' ],
                CU_SCANNER_VERSION,
                true
            );
            wp_localize_script( 'cu-scanner-history', 'cuScannerHistory', [
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( 'cu_scanner_nonce' ),
                'deleteWarning' => __(
                    "\xE2\x9A\xA0 This will permanently delete all scan history AND all stored scan JSON snapshots. Re-download links will stop working for old scans.\n\nDid you export a backup first?\n\nClick OK to delete everything, or Cancel to abort.",
                    'AI-Assets-Scanner'
                ),
            ] );
        }
    }

    public function maybe_render_history_deleted_notice(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== 'ai-assets-scanner_page_cu-scanner-history' ) {
            return;
        }
        $count = get_transient( 'cu_scanner_history_deleted_notice' );
        if ( $count === false ) {
            return;
        }
        delete_transient( 'cu_scanner_history_deleted_notice' );
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php
                // translators: %d = number of deleted records.
                printf( esc_html__( 'History deleted (%d records).', 'AI-Assets-Scanner' ), (int) $count );
            ?></p>
        </div>
        <?php
    }

    public function render_scanner(): void  { require CU_SCANNER_DIR . 'admin/views/scanner-page.php'; }
    public function render_settings(): void { require CU_SCANNER_DIR . 'admin/views/settings-page.php'; }
    public function render_history(): void  { require CU_SCANNER_DIR . 'admin/views/history-page.php'; }
}

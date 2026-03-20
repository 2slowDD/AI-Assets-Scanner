<?php
namespace CUScanner\Admin;

class AdminPages {
    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_menus(): void {
        add_menu_page(
            'CU Scanner', 'CU Scanner', 'manage_options',
            'cu-scanner', [ $this, 'render_scanner' ],
            'dashicons-search', 80
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
        $pages = [ 'toplevel_page_cu-scanner', 'cu-scanner_page_cu-scanner-settings', 'cu-scanner_page_cu-scanner-history' ];
        if ( ! in_array( $hook, $pages, true ) ) return;
        wp_enqueue_style( 'cu-scanner-admin', CU_SCANNER_URL . 'admin/css/cu-scanner-admin.css', [], CU_SCANNER_VERSION );
        if ( $hook === 'toplevel_page_cu-scanner' ) {
            wp_enqueue_script( 'cu-scanner-scanner', CU_SCANNER_URL . 'admin/js/scanner.js', [], CU_SCANNER_VERSION, true );
            wp_localize_script( 'cu-scanner-scanner', 'cuScanner', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'cu_scanner_nonce' ),
            ] );
        }
        if ( $hook === 'cu-scanner_page_cu-scanner-settings' ) {
            wp_enqueue_script( 'cu-scanner-settings', CU_SCANNER_URL . 'admin/js/settings.js', [], CU_SCANNER_VERSION, true );
            wp_localize_script( 'cu-scanner-settings', 'cuScannerSettings', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'cu_scanner_settings_nonce' ),
            ] );
        }
    }

    public function render_scanner(): void  { require CU_SCANNER_DIR . 'admin/views/scanner-page.php'; }
    public function render_settings(): void { require CU_SCANNER_DIR . 'admin/views/settings-page.php'; }
    public function render_history(): void  { require CU_SCANNER_DIR . 'admin/views/history-page.php'; }
}

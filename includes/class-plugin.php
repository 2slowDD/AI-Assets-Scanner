<?php
namespace CUScanner;

if ( ! defined( 'ABSPATH' ) ) exit;

use CUScanner\Scanner\BypassManager;
use CUScanner\Scanner\BypassHandler;
use CUScanner\Admin\AdminPages;
use CUScanner\Admin\SettingsAjax;
use CUScanner\Admin\ScannerAjax;

class Plugin {
    public function init(): void {
        if ( is_admin() ) {
            ( new AdminPages() )->register();
            ( new SettingsAjax() )->register();
            ( new ScannerAjax() )->register();
        }
        // Frontend: bypass token hook (runs on every request, not just admin)
        $bypass = new BypassManager();
        add_action( 'wp_loaded', [ $bypass, 'handle_wp_loaded' ] );

        // Class A optimizer hook-removal (priority 0 — before BypassManager's default priority).
        BypassHandler::init();
    }
}

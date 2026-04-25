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

        // Class C orchestrator: stale-state self-heal + watchdog hooks.
        \CUScanner\Scanner\OptimizerBypassOrchestrator::init();

        // Admin banner + force-restore handler.
        \CUScanner\Admin\OptimizerStateNotices::init();

        // Class C scan-complete restore: fires when build_result() writes 'complete' to scan history.
        add_action( 'cu_scanner_scan_complete', static function ( $scan_id ) {
            $state = \CUScanner\Scanner\OptimizerState::load();
            if ( ! $state || ( $state['scan_id'] ?? '' ) !== (string) $scan_id ) return;
            \CUScanner\Scanner\OptimizerBypassOrchestrator::build_default_orchestrator()
                ->complete( 'normal' );
        }, 10, 1 );
    }
}

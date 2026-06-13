<?php
/**
 * Plugin Name: AI Assets Scanner
 * Description: AI-powered CSS/JS asset scanner by WPservice.pro.
 * Version:     1.7.36b
 * Author:      WPservice.pro
 * Author URI:  https://wpservice.pro/
 * Requires PHP: 8.0
 * Requires at least: 6.2
 * Tested up to: 7.0
 * Text Domain: AI-Assets-Scanner
 * License:     Proprietary source-available
 */
/*
 * Copyright (C) 2026 Ermada / WPservice.pro / Dalibor Druzinec. All rights reserved.
 *
 * This plugin is proprietary source-available software. You may copy, install,
 * and use unmodified copies. You may not modify, fork, sublicense, resell,
 * rebrand, redistribute modified copies, remove license/API checks, or create
 * derivative works based on this plugin without explicit written permission
 * from Ermada / WPservice.pro.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CU_SCANNER_VERSION', '1.7.36b' );
define( 'CU_SCANNER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CU_SCANNER_URL', plugin_dir_url( __FILE__ ) );
define( 'CU_SCANNER_WPSERVICE_BASE', 'https://wpservice.pro' );
define( 'CU_SCANNER_WPSERVICE_URL',  CU_SCANNER_WPSERVICE_BASE . '/wp-json' );

require_once CU_SCANNER_DIR . 'includes/debug.php';

spl_autoload_register( function ( string $class ): void {
    $map = [
        'CUScanner\\Plugin'           => 'includes/class-plugin.php',
        'CUScanner\\Settings'         => 'includes/class-settings.php',
        'CUScanner\\DomainNormalizer' => 'includes/class-domain-normalizer.php',
        'CUScanner\\FreeKeyBootstrap' => 'includes/class-free-key-bootstrap.php',
        'CUScanner\\ScanHistory'      => 'includes/class-scan-history.php',
        'CUScanner\\Api\\WpserviceClient' => 'includes/api/class-wpservice-client.php',
        'CUScanner\\Api\\RailwayClient'   => 'includes/api/class-railway-client.php',
        'CUScanner\\MenuBadge'           => 'includes/class-menu-badge.php',
        'CUScanner\\Scanner\\PageDiscovery'   => 'includes/scanner/class-page-discovery.php',
        'CUScanner\\Scanner\\PluginDetector'  => 'includes/scanner/class-plugin-detector.php',
        'CUScanner\\Scanner\\BypassManager'   => 'includes/scanner/class-bypass-manager.php',
        'CUScanner\\Scanner\\OptimizerState'  => 'includes/scanner/class-optimizer-state.php',
        'CUScanner\\Scanner\\BypassHandler'   => 'includes/scanner/class-bypass-handler.php',
        'CUScanner\\Scanner\\Strategies\\AbstractOptimizerBypass' => 'includes/scanner/strategies/abstract-optimizer-bypass.php',
        'CUScanner\\Scanner\\Strategies\\FlyingPressBypass'        => 'includes/scanner/strategies/class-flying-press-bypass.php',
        'CUScanner\\Scanner\\Strategies\\SgOptimizerBypass'        => 'includes/scanner/strategies/class-sg-optimizer-bypass.php',
        'CUScanner\\Scanner\\Strategies\\HummingbirdBypass'        => 'includes/scanner/strategies/class-hummingbird-bypass.php',
        'CUScanner\\Scanner\\OptimizerBypassOrchestrator' => 'includes/scanner/class-optimizer-bypass-orchestrator.php',
        'CUScanner\\Scanner\\StrategyFactory'             => 'includes/scanner/class-strategy-factory.php',
        'CUScanner\\Scanner\\EventEmitter'    => 'includes/scanner/class-event-emitter.php',
        'CUScanner\\Scanner\\CuJsonBuilder'   => 'includes/scanner/class-cu-json-builder.php',
        'CUScanner\\Scanner\\RatchetMerger'   => 'includes/scanner/class-ratchet-merger.php',
        'CUScanner\\Scanner\\RulePusher'      => 'includes/scanner/class-rule-pusher.php',
        'CUScanner\\Scanner\\SnapshotManager' => 'includes/scanner/class-snapshot-manager.php',
        'CUScanner\\Scanner\\GroupVersionManager' => 'includes/scanner/class-group-version-manager.php',
        'CUScanner\\Admin\\AdminPages'        => 'admin/class-admin-pages.php',
        'CUScanner\\Admin\\SettingsAjax'      => 'admin/class-settings-ajax.php',
        'CUScanner\\Admin\\ScannerAjax'       => 'admin/class-scanner-ajax.php',
        'CUScanner\\Admin\\PrivateUpdater'    => 'includes/admin/class-private-updater.php',
        'CUScanner\\Scanner\\RestPreflight'       => 'includes/scanner/class-rest-preflight.php',
        'CUScanner\\Admin\\OptimizerStateNotices' => 'includes/admin/class-optimizer-state-notices.php',
        'AIAS_Broken_Banner'                     => 'includes/class-broken-banner.php',
        'AIAS_Scan_Status'                       => 'includes/class-scan-status.php',
    ];
    if ( isset( $map[ $class ] ) ) {
        require CU_SCANNER_DIR . $map[ $class ];
    }
} );

add_action( 'rest_api_init', [ \CUScanner\Scanner\RestPreflight::class, 'register_routes' ] );

register_activation_hook( __FILE__, function (): void {
    ( new \CUScanner\FreeKeyBootstrap() )->run();
} );

add_action( 'admin_init', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $settings = new \CUScanner\Settings();
    if ( \CUScanner\FreeKeyBootstrap::should_run_from_admin( $settings ) ) {
        ( new \CUScanner\FreeKeyBootstrap( $settings ) )->run();
    }
} );

add_action( 'cu_scanner_free_key_retry', function (): void {
    ( new \CUScanner\FreeKeyBootstrap() )->run();
} );

add_action( 'plugins_loaded', function (): void {
    ( new CUScanner\Plugin() )->init();
} );

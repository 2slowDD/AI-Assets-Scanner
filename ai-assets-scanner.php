<?php
/**
 * Plugin Name: AI Assets Scanner
 * Description: AI-powered CSS/JS asset scanner by WPservice.pro.
 * Version:     1.2.0f
 * Requires PHP: 8.0
 * Requires at least: 6.2
 * Text Domain: cu-scanner
 * License:     Proprietary
 */
/*
 * Copyright (C) 2026 WPservice.pro. All rights reserved.
 * Modification or redistribution without written permission is prohibited.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CU_SCANNER_VERSION', '1.2.0f' );
define( 'CU_SCANNER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CU_SCANNER_URL', plugin_dir_url( __FILE__ ) );
define( 'CU_SCANNER_WPSERVICE_BASE', 'https://wpservice.pro' );
define( 'CU_SCANNER_WPSERVICE_URL',  CU_SCANNER_WPSERVICE_BASE . '/wp-json' );

spl_autoload_register( function ( string $class ): void {
    $map = [
        'CUScanner\\Plugin'           => 'includes/class-plugin.php',
        'CUScanner\\Settings'         => 'includes/class-settings.php',
        'CUScanner\\ScanHistory'      => 'includes/class-scan-history.php',
        'CUScanner\\Api\\WpserviceClient' => 'includes/api/class-wpservice-client.php',
        'CUScanner\\Api\\RailwayClient'   => 'includes/api/class-railway-client.php',
        'CUScanner\\Scanner\\PageDiscovery'   => 'includes/scanner/class-page-discovery.php',
        'CUScanner\\Scanner\\PluginDetector'  => 'includes/scanner/class-plugin-detector.php',
        'CUScanner\\Scanner\\BypassManager'   => 'includes/scanner/class-bypass-manager.php',
        'CUScanner\\Scanner\\OptimizerState'  => 'includes/scanner/class-optimizer-state.php',
        'CUScanner\\Scanner\\BypassHandler'   => 'includes/scanner/class-bypass-handler.php',
        'CUScanner\\Scanner\\Strategies\\AbstractOptimizerBypass' => 'includes/scanner/strategies/abstract-optimizer-bypass.php',
        'CUScanner\\Scanner\\Strategies\\FlyingPressBypass'        => 'includes/scanner/strategies/class-flying-press-bypass.php',
        'CUScanner\\Scanner\\OptimizerBypassOrchestrator' => 'includes/scanner/class-optimizer-bypass-orchestrator.php',
        'CUScanner\\Scanner\\StrategyFactory'             => 'includes/scanner/class-strategy-factory.php',
        'CUScanner\\Scanner\\EventEmitter'    => 'includes/scanner/class-event-emitter.php',
        'CUScanner\\Scanner\\CuJsonBuilder'   => 'includes/scanner/class-cu-json-builder.php',
        'CUScanner\\Scanner\\RulePusher'      => 'includes/scanner/class-rule-pusher.php',
        'CUScanner\\Scanner\\SnapshotManager' => 'includes/scanner/class-snapshot-manager.php',
        'CUScanner\\Scanner\\GroupVersionManager' => 'includes/scanner/class-group-version-manager.php',
        'CUScanner\\Admin\\AdminPages'        => 'admin/class-admin-pages.php',
        'CUScanner\\Admin\\SettingsAjax'      => 'admin/class-settings-ajax.php',
        'CUScanner\\Admin\\ScannerAjax'       => 'admin/class-scanner-ajax.php',
    ];
    if ( isset( $map[ $class ] ) ) {
        require CU_SCANNER_DIR . $map[ $class ];
    }
} );

add_action( 'plugins_loaded', function (): void {
    ( new CUScanner\Plugin() )->init();
} );

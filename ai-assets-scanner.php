<?php
/**
 * Plugin Name: AI Assets Scanner
 * Description: AI-powered CSS/JS asset scanner by WPservice.pro.
 * Version:     1.7.97b
 * Author:      WPservice.pro
 * Author URI:  https://wpservice.pro/
 * Requires PHP: 8.0
 * Requires at least: 6.2
 * Tested up to: 7.0.4
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

define( 'CU_SCANNER_VERSION', '1.7.97b' );
define( 'CU_SCANNER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CU_SCANNER_URL', plugin_dir_url( __FILE__ ) );
define( 'CU_SCANNER_WPSERVICE_BASE', 'https://wpservice.pro' );
define( 'CU_SCANNER_WPSERVICE_URL',  CU_SCANNER_WPSERVICE_BASE . '/wp-json' );

require_once CU_SCANNER_DIR . 'includes/debug.php';

spl_autoload_register( function ( string $class ): void {
    // Single source of truth, shared with tests/bootstrap.php. Two hand-maintained
    // copies used to drift silently: a class registered here but not there (or vice
    // versa) kept the suite green while a live site fatalled on first use.
    $map = require CU_SCANNER_DIR . 'includes/autoload-map.php';
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

add_action( \CUScanner\Scanner\Outbox::CRON_HOOK, [ \CUScanner\Scanner\Outbox::class, 'replay' ] );

add_action( 'plugins_loaded', function (): void {
    \CUScanner\Migrations::maybe_run(); // O(1) alloptions lookup once migrated
    ( new CUScanner\Plugin() )->init();
} );

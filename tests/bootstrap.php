<?php
require_once __DIR__ . '/../vendor/autoload.php';

define( 'ABSPATH', '/fake/wp/' );
define( 'CU_SCANNER_DIR', dirname( __DIR__ ) . '/' );
define( 'CU_SCANNER_VERSION', '1.0.0' );

spl_autoload_register( function ( string $class ): void {
    $map = [
        'CUScanner\\Plugin'                   => 'includes/class-plugin.php',
        'CUScanner\\Settings'                 => 'includes/class-settings.php',
        'CUScanner\\ScanHistory'              => 'includes/class-scan-history.php',
        'CUScanner\\Api\\WpserviceClient'     => 'includes/api/class-wpservice-client.php',
        'CUScanner\\Api\\RailwayClient'       => 'includes/api/class-railway-client.php',
        'CUScanner\\Scanner\\PageDiscovery'   => 'includes/scanner/class-page-discovery.php',
        'CUScanner\\Scanner\\PluginDetector'  => 'includes/scanner/class-plugin-detector.php',
        'CUScanner\\Scanner\\BypassManager'   => 'includes/scanner/class-bypass-manager.php',
        'CUScanner\\Scanner\\CuJsonBuilder'   => 'includes/scanner/class-cu-json-builder.php',
        'CUScanner\\Scanner\\RulePusher'      => 'includes/scanner/class-rule-pusher.php',
    ];
    if ( isset( $map[ $class ] ) ) {
        require CU_SCANNER_DIR . $map[ $class ];
    }
} );

WP_Mock::bootstrap();

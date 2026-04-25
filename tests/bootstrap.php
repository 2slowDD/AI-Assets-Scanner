<?php
require_once __DIR__ . '/../vendor/autoload.php';

define( 'ABSPATH', '/fake/wp/' );
define( 'WP_PLUGIN_DIR', '/fake/wp/wp-content/plugins' );
define( 'CU_SCANNER_DIR', dirname( __DIR__ ) . '/' );
define( 'CU_SCANNER_VERSION', '1.0.0' );
define( 'CU_SCANNER_WPSERVICE_URL', 'https://api.wpservice.pro' );

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public function __construct( private string $code = '', private string $message = '' ) {}
        public function get_error_message(): string { return $this->message; }
        public function get_error_code(): string { return $this->code; }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( mixed $thing ): bool {
        return $thing instanceof WP_Error;
    }
}

spl_autoload_register( function ( string $class ): void {
    $map = [
        'CUScanner\\Admin\\ScannerAjax'        => 'admin/class-scanner-ajax.php',
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
        'CUScanner\\Scanner\\SnapshotManager' => 'includes/scanner/class-snapshot-manager.php',
        'CUScanner\\Scanner\\GroupVersionManager' => 'includes/scanner/class-group-version-manager.php',
        'CUScanner\\Scanner\\EventEmitter'       => 'includes/scanner/class-event-emitter.php',
        'CUScanner\\Scanner\\BypassHandler'      => 'includes/scanner/class-bypass-handler.php',
    ];
    if ( isset( $map[ $class ] ) ) {
        require CU_SCANNER_DIR . $map[ $class ];
    }
} );

WP_Mock::bootstrap();

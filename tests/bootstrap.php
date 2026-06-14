<?php
require_once __DIR__ . '/../vendor/autoload.php';

define( 'ABSPATH', '/fake/wp/' );
define( 'WP_PLUGIN_DIR', '/fake/wp/wp-content/plugins' );
define( 'CU_SCANNER_DIR', dirname( __DIR__ ) . '/' );
define( 'CU_SCANNER_VERSION', '1.0.0' );
define( 'CU_SCANNER_URL', 'https://example.test/wp-content/plugins/ai-assets-scanner/' );
define( 'CU_SCANNER_WPSERVICE_URL', 'https://api.wpservice.pro' );
require_once CU_SCANNER_DIR . 'includes/debug.php';
defined( 'HOUR_IN_SECONDS' )   || define( 'HOUR_IN_SECONDS',   3600 );
defined( 'DAY_IN_SECONDS' )    || define( 'DAY_IN_SECONDS',    86400 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

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

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $params = [];
        public function __construct( private string $method = 'GET', private string $route = '' ) {}
        public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
        public function get_json_params(): array { return $this->params; }
        public function set_param( string $key, mixed $value ): void { $this->params[ $key ] = $value; }
        public function get_method(): string { return $this->method; }
    }
}

spl_autoload_register( function ( string $class ): void {
    $map = [
        'CUScanner\\Admin\\ScannerAjax'        => 'admin/class-scanner-ajax.php',
        'CUScanner\\Plugin'                   => 'includes/class-plugin.php',
        'CUScanner\\Settings'                 => 'includes/class-settings.php',
        'CUScanner\\DomainNormalizer'         => 'includes/class-domain-normalizer.php',
        'CUScanner\\FreeKeyBootstrap'         => 'includes/class-free-key-bootstrap.php',
        'CUScanner\\ScanHistory'              => 'includes/class-scan-history.php',
        'CUScanner\\Api\\WpserviceClient'     => 'includes/api/class-wpservice-client.php',
        'CUScanner\\Api\\RailwayClient'       => 'includes/api/class-railway-client.php',
        'CUScanner\\Api\\HttpException'       => 'includes/api/class-http-exception.php',
        'CUScanner\\MenuBadge'               => 'includes/class-menu-badge.php',
        'CUScanner\\Scanner\\PageDiscovery'   => 'includes/scanner/class-page-discovery.php',
        'CUScanner\\Scanner\\PluginDetector'  => 'includes/scanner/class-plugin-detector.php',
        'CUScanner\\Scanner\\BypassManager'   => 'includes/scanner/class-bypass-manager.php',
        'CUScanner\\Scanner\\OptimizerState'  => 'includes/scanner/class-optimizer-state.php',
        'CUScanner\\Scanner\\CuJsonBuilder'   => 'includes/scanner/class-cu-json-builder.php',
        'CUScanner\\Scanner\\RatchetMerger'   => 'includes/scanner/class-ratchet-merger.php',
        'CUScanner\\Scanner\\RulePusher'      => 'includes/scanner/class-rule-pusher.php',
        'CUScanner\\Scanner\\SnapshotManager' => 'includes/scanner/class-snapshot-manager.php',
        'CUScanner\\Scanner\\GroupVersionManager' => 'includes/scanner/class-group-version-manager.php',
        'CUScanner\\Scanner\\OptimizerBypassOrchestrator' => 'includes/scanner/class-optimizer-bypass-orchestrator.php',
        'CUScanner\\Scanner\\StrategyFactory'             => 'includes/scanner/class-strategy-factory.php',
        'CUScanner\\Scanner\\EventEmitter'       => 'includes/scanner/class-event-emitter.php',
        'CUScanner\\Scanner\\BypassHandler'      => 'includes/scanner/class-bypass-handler.php',
        'CUScanner\\Scanner\\Strategies\\AbstractOptimizerBypass' => 'includes/scanner/strategies/abstract-optimizer-bypass.php',
        'CUScanner\\Scanner\\Strategies\\FlyingPressBypass'        => 'includes/scanner/strategies/class-flying-press-bypass.php',
        'CUScanner\\Scanner\\Strategies\\SgOptimizerBypass'        => 'includes/scanner/strategies/class-sg-optimizer-bypass.php',
        'CUScanner\\Scanner\\Strategies\\HummingbirdBypass'        => 'includes/scanner/strategies/class-hummingbird-bypass.php',
        'CUScanner\\Scanner\\RestPreflight'       => 'includes/scanner/class-rest-preflight.php',
        'CUScanner\\Admin\\OptimizerStateNotices' => 'includes/admin/class-optimizer-state-notices.php',
        'CUScanner\\Admin\\PrivateUpdater'        => 'includes/admin/class-private-updater.php',
        'AIAS_Broken_Banner'                     => 'includes/class-broken-banner.php',
        'AIAS_Scan_Status'                       => 'includes/class-scan-status.php',
    ];
    if ( isset( $map[ $class ] ) ) {
        require CU_SCANNER_DIR . $map[ $class ];
    }
} );

WP_Mock::bootstrap();

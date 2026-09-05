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

// FU-AAS-DISCOVER-HOMEPAGE-FIRST (1.8.5) — minimal WP_Query stub so ScannerAjax::discover_pages can
// be driven as a REAL handler. Lives HERE behind class_exists (the WP_Error / WP_REST_Request
// pattern), NEVER in a test file: PHPUnit loads test files alphabetically and a process-global class
// declared in one file collides with, or silently shadows for, every later file. Tests set
// WP_Query::$next_posts and reset it to [] in BOTH setUp and tearDown.
if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        public static array $next_posts = [];
        public array $posts;
        public function __construct( array $args = [] ) { $this->posts = self::$next_posts; }
    }
}

spl_autoload_register( function ( string $class ): void {
    // Shared with ai-assets-scanner.php so the suite exercises the REAL production
    // autoload map, not a hand-maintained test-only copy that can silently drift.
    $map = require CU_SCANNER_DIR . 'includes/autoload-map.php';
    if ( isset( $map[ $class ] ) ) {
        require CU_SCANNER_DIR . $map[ $class ];
    }
} );

WP_Mock::bootstrap();

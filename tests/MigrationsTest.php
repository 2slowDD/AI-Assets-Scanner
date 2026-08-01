<?php

use WP_Mock\Tools\TestCase;

/**
 * Tests for CUScanner\Migrations (FU-AAS-AUTOLOAD-BLOAT).
 *
 * The wpdb fake models the REAL wpdb contract: last_error is PER-QUERY —
 * wpdb::query() calls flush(), which resets last_error to '' before every
 * query. A fake that lets last_error accumulate would certify broken
 * error handling (design review r1, Critical).
 */
class FlushingWpdbFake {
    public string $options    = 'wp_options';
    public string $last_error = '';
    /** @var array<int, array{result: mixed, error?: string}> consumed in order, one per query */
    private array $script;
    /** @var array<int, array{0: string, 1: string}> [method, interpolated SQL] */
    public array $log = [];

    public function __construct( array $script ) {
        $this->script = $script;
    }

    public function esc_like( string $value ): string {
        return addcslashes( $value, '_%\\' );
    }

    public function prepare( string $query, ...$args ): string {
        if ( isset( $args[0] ) && is_array( $args[0] ) ) {
            $args = $args[0]; // real wpdb::prepare() accepts a single array of values
        }
        return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
    }

    private function step( string $method, string $sql ) {
        $this->last_error = ''; // models wpdb::query() -> flush(): per-query, never accumulating
        $this->log[]      = [ $method, $sql ];
        $s                = array_shift( $this->script );
        if ( null === $s ) {
            throw new \RuntimeException( 'FlushingWpdbFake: unscripted query: ' . $sql );
        }
        $this->last_error = $s['error'] ?? '';
        return $s['result'];
    }

    public function get_col( string $sql ): array {
        $r = $this->step( 'get_col', $sql );
        return is_array( $r ) ? $r : [];
    }

    public function get_var( string $sql ) {
        return $this->step( 'get_var', $sql );
    }

    public function query( string $sql ) {
        return $this->step( 'query', $sql );
    }
}

class MigrationsTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        unset( $GLOBALS['wpdb'] );
        parent::tearDown();
    }

    /** Installs at current DB_VERSION must touch nothing — not even $wpdb. */
    public function test_version_gate_skips_when_current(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_db_version', 0 )
            ->andReturn( 1 );
        WP_Mock::userFunction( 'update_option' )->never();

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [] ); // any query throws "unscripted"

        \CUScanner\Migrations::maybe_run();
        $this->assertSame( [], $GLOBALS['wpdb']->log );
    }

    /** plugins_loaded fires during core install/upgrade — must no-op there. */
    public function test_wp_installing_gate(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( true );
        WP_Mock::userFunction( 'get_option' )->never();
        WP_Mock::userFunction( 'update_option' )->never();

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [] );

        \CUScanner\Migrations::maybe_run();
        $this->assertSame( [], $GLOBALS['wpdb']->log );
    }
}

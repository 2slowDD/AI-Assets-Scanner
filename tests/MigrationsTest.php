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

    /**
     * Installs at current DB_VERSION must touch nothing — not even $wpdb.
     *
     * `->once()` on the reads is load-bearing: WP_Mock::userFunction() without an
     * explicit cardinality asserts NOTHING about being called, so a no-op
     * `maybe_run() {}` would satisfy the ->never() + empty-log assertions alone.
     * These ->once() calls are what prove the gate actually ran and read the option.
     */
    public function test_version_gate_skips_when_current(): void {
        WP_Mock::userFunction( 'wp_installing' )->once()->andReturn( false );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_db_version', 0 )
            ->once()
            ->andReturn( 1 );
        WP_Mock::userFunction( 'update_option' )->never();

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [] ); // any query throws "unscripted"

        \CUScanner\Migrations::maybe_run();
        $this->assertSame( [], $GLOBALS['wpdb']->log );
    }

    /** plugins_loaded fires during core install/upgrade — must no-op there. */
    public function test_wp_installing_gate(): void {
        WP_Mock::userFunction( 'wp_installing' )->once()->andReturn( true );
        WP_Mock::userFunction( 'get_option' )->never();
        WP_Mock::userFunction( 'update_option' )->never();

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [] );

        \CUScanner\Migrations::maybe_run();
        $this->assertSame( [], $GLOBALS['wpdb']->log );
    }

    /** Happy path: enumerate, flip, verify 0 remaining, stamp version. */
    public function test_m1_flips_enumerated_rows_and_stamps_version(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_db_version', 0 )
            ->andReturn( 0 );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_db_version', 1 )
            ->once()
            ->andReturn( true );
        WP_Mock::userFunction( 'wp_cache_delete' )
            ->with( 'alloptions', 'options' )
            ->once()
            ->andReturn( true );

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [
            [ 'result' => [ 'cu_scanner_json_aaa', 'cu_scanner_json_bbb' ] ], // SELECT
            [ 'result' => 3 ],  // UPDATE: 3 rows changed (2 json + history)
            [ 'result' => '0' ],  // COUNT post-verify: none still autoloading
        ] );

        \CUScanner\Migrations::maybe_run();

        $wpdb = $GLOBALS['wpdb'];
        $this->assertCount( 3, $wpdb->log );
        [ $sel, $upd, $cnt ] = $wpdb->log;
        $this->assertSame( 'get_col', $sel[0] );
        $this->assertStringContainsString( "LIKE 'cu\\_scanner\\_json\\_%'", $sel[1] );
        $this->assertSame( 'query', $upd[0] );
        $this->assertStringContainsString( "SET autoload = 'no'", $upd[1] );
        $this->assertStringContainsString( "'cu_scanner_json_aaa'", $upd[1] );
        $this->assertStringContainsString( "'cu_scanner_json_bbb'", $upd[1] );
        $this->assertStringContainsString( "'cu_scanner_history'", $upd[1] );
        // Targets ONLY enumerated names — config options are never in the IN list.
        $this->assertStringNotContainsString( 'api_key', $upd[1] );
        $this->assertStringNotContainsString( 'railway_url', $upd[1] );
        $this->assertSame( 'get_var', $cnt[0] );
        $this->assertStringContainsString( "autoload IN ( 'yes', 'on', 'auto-on', 'auto' )", $cnt[1] );
    }

    /** Orphaned JSON rows (no history record) come from the SELECT — still flipped. */
    public function test_m1_covers_orphaned_json_rows(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_db_version', 0 )->andReturn( 0 );
        WP_Mock::userFunction( 'update_option' )->with( 'cu_scanner_db_version', 1 )->once()->andReturn( true );
        WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [
            [ 'result' => [ 'cu_scanner_json_orphan' ] ], // exists in options, absent from history
            [ 'result' => 2 ],
            [ 'result' => '0' ],
        ] );

        \CUScanner\Migrations::maybe_run();
        $this->assertStringContainsString( "'cu_scanner_json_orphan'", $GLOBALS['wpdb']->log[1][1] );
    }

    /** Idempotence: re-run with 0 rows to change (query() returns 0, not false) still succeeds. */
    public function test_m1_idempotent_rerun_zero_rows_is_success(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_db_version', 0 )->andReturn( 0 );
        WP_Mock::userFunction( 'update_option' )->with( 'cu_scanner_db_version', 1 )->once()->andReturn( true );
        WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [
            [ 'result' => [ 'cu_scanner_json_aaa' ] ],
            [ 'result' => 0 ],   // UPDATE affected 0 rows — already 'no'; NOT an error
            [ 'result' => '0' ],
        ] );

        \CUScanner\Migrations::maybe_run(); // version stamped ⇒ update_option ->once() satisfied
        $this->assertCount( 3, $GLOBALS['wpdb']->log );
    }
}

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
        // Post-verify must re-check BOTH targets — dropping either half would let a
        // half-migrated install stamp the version as complete.
        $this->assertStringContainsString( "LIKE 'cu\\_scanner\\_json\\_%'", $cnt[1] );
        $this->assertStringContainsString( "'cu_scanner_history'", $cnt[1] );
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

    /**
     * THE r1-CRITICAL REGRESSION LOCK: failing SELECT followed by queries that
     * would SUCCEED (and wipe last_error, as real wpdb does) must NOT stamp the
     * version. v1's end-of-function last_error check passed exactly this case.
     */
    public function test_m1_failing_select_then_succeeding_queries_does_not_stamp_version(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_db_version', 0 )->andReturn( 0 );
        WP_Mock::userFunction( 'update_option' )->never();

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [
            [ 'result' => [], 'error' => 'Table gone' ], // SELECT fails
            [ 'result' => 1 ],   // would-be UPDATE — succeeds and wipes last_error
            [ 'result' => '0' ], // would-be COUNT — succeeds
        ] );

        \CUScanner\Migrations::maybe_run();
        // Correct behaviour bails after the SELECT: exactly 1 query issued.
        $this->assertCount( 1, $GLOBALS['wpdb']->log );
    }

    /** UPDATE returning false ⇒ no version stamp. */
    public function test_m1_update_failure_does_not_stamp_version(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_db_version', 0 )->andReturn( 0 );
        WP_Mock::userFunction( 'update_option' )->never();

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [
            [ 'result' => [ 'cu_scanner_json_aaa' ] ],
            [ 'result' => false, 'error' => 'Deadlock found' ],
        ] );

        \CUScanner\Migrations::maybe_run();
        $this->assertCount( 2, $GLOBALS['wpdb']->log );
    }

    /** Post-verify COUNT > 0 (e.g. lagging read replica) ⇒ no stamp; retry self-heals. */
    public function test_m1_nonzero_postverify_does_not_stamp_version(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_db_version', 0 )->andReturn( 0 );
        WP_Mock::userFunction( 'update_option' )->never();
        WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [
            [ 'result' => [ 'cu_scanner_json_aaa' ] ],
            [ 'result' => 2 ],
            [ 'result' => '2' ], // still autoloading per the re-read
        ] );

        \CUScanner\Migrations::maybe_run();
        $this->assertCount( 3, $GLOBALS['wpdb']->log );
    }

    /** Post-verify get_var ERROR returns null — must NOT be read as count 0. */
    public function test_m1_failed_postverify_query_does_not_stamp_version(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_db_version', 0 )->andReturn( 0 );
        WP_Mock::userFunction( 'update_option' )->never();
        WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [
            [ 'result' => [ 'cu_scanner_json_aaa' ] ],
            [ 'result' => 1 ],
            [ 'result' => null, 'error' => 'Server has gone away' ],
        ] );

        \CUScanner\Migrations::maybe_run();
        $this->assertCount( 3, $GLOBALS['wpdb']->log );
    }

    /**
     * Post-verify get_var() returning null with NO error must still block the stamp.
     * This isolates the `null === $remaining` guard: without it, `(int) null === 0`
     * reads as "0 rows still autoloading" and the migration stamps itself complete
     * having verified nothing. The sibling test pairs null WITH an error string, so
     * the last_error check alone would mask a missing null guard — only this script
     * fails when the guard is dropped.
     */
    public function test_m1_null_postverify_without_error_does_not_stamp_version(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_db_version', 0 )->andReturn( 0 );
        WP_Mock::userFunction( 'update_option' )->never();
        WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [
            [ 'result' => [ 'cu_scanner_json_aaa' ] ],
            [ 'result' => 1 ],
            [ 'result' => null ], // null WITHOUT last_error — only the null guard catches this
        ] );

        \CUScanner\Migrations::maybe_run();
        $this->assertCount( 3, $GLOBALS['wpdb']->log );
    }

    /**
     * Zero cu_scanner_json_* rows is a SUCCESS path, not a bail-out. The enumeration
     * legitimately returns [] on an install whose JSON blobs were already evicted (or
     * that has none yet), and `cu_scanner_history` — a primary target of this FU — must
     * still be flipped. This locks the distinction between "SELECT returned nothing" and
     * "SELECT failed": guarding on empty( $names ) instead of last_error would bail on
     * every request forever on exactly those installs, never flipping history and never
     * stamping the version.
     */
    public function test_m1_empty_enumeration_still_flips_history(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_db_version', 0 )->andReturn( 0 );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_db_version', 1 )
            ->once()
            ->andReturn( true );
        WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [
            [ 'result' => [] ], // no JSON blobs, and NO error — a legitimate empty result
            [ 'result' => 1 ],
            [ 'result' => '0' ],
        ] );

        \CUScanner\Migrations::maybe_run();

        $this->assertCount( 3, $GLOBALS['wpdb']->log );
        $this->assertStringContainsString( "'cu_scanner_history'", $GLOBALS['wpdb']->log[1][1] );
    }

    /**
     * FIELD-FAILURE REGRESSION LOCK (1.7.84b, live install 2026-08-01).
     * A 1.2.x-era build wrote the plugin version string into cu_scanner_db_version.
     * `(int) '1.2.41'` === 1 === DB_VERSION, so the gate skipped the migration forever.
     * A foreign (non-integer) stored value MUST read as 0 so the ladder still runs.
     */
    public function test_legacy_version_string_relic_does_not_block_migration(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_db_version', 0 )
            ->andReturn( '1.2.41' );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_db_version', 1 )
            ->once()
            ->andReturn( true );
        WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [
            [ 'result' => [ 'cu_scanner_json_aaa' ] ],
            [ 'result' => 2 ],
            [ 'result' => '0' ],
        ] );

        \CUScanner\Migrations::maybe_run();
        $this->assertCount( 3, $GLOBALS['wpdb']->log ); // m1 actually ran
    }

    /** Empty-string stored value is foreign too — ladder must run. */
    public function test_empty_string_version_does_not_block_migration(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_db_version', 0 )->andReturn( '' );
        WP_Mock::userFunction( 'update_option' )->with( 'cu_scanner_db_version', 1 )->once()->andReturn( true );
        WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [
            [ 'result' => [] ],
            [ 'result' => 1 ],
            [ 'result' => '0' ],
        ] );

        \CUScanner\Migrations::maybe_run();
        $this->assertCount( 3, $GLOBALS['wpdb']->log );
    }

    /** Non-numeric junk (e.g. a serialized array left by an old build) — ladder must run. */
    public function test_non_numeric_junk_version_does_not_block_migration(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_db_version', 0 )->andReturn( 'a:1:{i:0;s:3:"old";}' );
        WP_Mock::userFunction( 'update_option' )->with( 'cu_scanner_db_version', 1 )->once()->andReturn( true );
        WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [
            [ 'result' => [] ],
            [ 'result' => 1 ],
            [ 'result' => '0' ],
        ] );

        \CUScanner\Migrations::maybe_run();
        $this->assertCount( 3, $GLOBALS['wpdb']->log );
    }

    /** VALID-PATH REGRESSION: a genuine integer-string stamp must still skip. */
    public function test_valid_integer_string_version_still_skips(): void {
        WP_Mock::userFunction( 'wp_installing' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_db_version', 0 )->andReturn( '1' );
        WP_Mock::userFunction( 'update_option' )->never();

        $GLOBALS['wpdb'] = new FlushingWpdbFake( [] );

        \CUScanner\Migrations::maybe_run();
        $this->assertSame( [], $GLOBALS['wpdb']->log );
    }
}

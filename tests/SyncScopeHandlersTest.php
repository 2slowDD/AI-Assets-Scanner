<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

require_once __DIR__ . '/SnapshotManagerTest.php';   // FakeRuleRepository
require_once __DIR__ . '/SyncScopeBuildResultTest.php'; // SyncScopeBuildResultTestFixtureAccess (AC-9)
if ( ! class_exists( 'CodeUnloader\\Core\\RuleRepository', false ) ) {
    class_alias( FakeRuleRepository::class, 'CodeUnloader\\Core\\RuleRepository' );
}

/**
 * FU-AAS-SYNC-SCOPE-LAST-SCAN — handler side, through the REAL sync_to_cu / push_to_cu /
 * undo_last_push_sync with RulePusher's default repo aliased to FakeRuleRepository.
 * Fixture A (spec §5): scanned_patterns=[home]; rules = home x6 (A) + carried other-page x13 (S1 + A12).
 *   AC-2(a)(b) Sync counts; AC-3 Push scoped (option 1); AC-4 fallback + fail-closed on BOTH handlers;
 *   AC-6 undo scoped; AC-8 host x scope composition; AC-9 the AC-1-produced JSON round-trips.
 *
 * Controller Ruling B (task-2, 2026-09-05): tests/ResultTruthRefundClaimTest.php ALREADY does
 * `class_alias( FakeCuRepo::class, 'CodeUnloader\\Core\\RuleRepository' )` at FILE LOAD, guarded
 * by class_exists, and PHPUnit loads it before this file (alphabetical). FakeCuRepo has no
 * create_rule()/create_group(), so inside the FULL suite this file's own class_exists() guard
 * above would be a no-op (the alias already exists) and every handler test here would fatal
 * calling FakeCuRepo::create_rule(). @runTestsInSeparateProcesses + @preserveGlobalState disabled
 * at CLASS level give each test a fresh PHP process where THIS file's alias binds first.
 * NOTE: the class-level per-METHOD-isolation annotation is @runTestsInSeparateProcesses (plural) —
 * @runInSeparateProcess (singular) is method-scoped only (PHPUnit\Util\Test::getProcessIsolationSettings()
 * checks $annotations['class']['runTestsInSeparateProcesses'] || $annotations['method']['runInSeparateProcess']);
 * using the singular form at class level silently does nothing, verified empirically (D0 below).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SyncScopeHandlersTest extends TestCase {
    /** @var array<string,mixed> */
    private array $options = [];
    private $captured = null;
    private $error = null;

    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); FakeRuleRepository::reset(); $this->options = []; $this->captured = null; $this->error = null; }
    public function tearDown(): void { unset( $_POST['job_id'], $_POST['confirmed'] ); WP_Mock::tearDown(); parent::tearDown(); }

    private function rule( string $pattern, string $handle, int $group = 2 ): array {
        return [ 'url_pattern' => $pattern, 'match_type' => 'exact', 'asset_handle' => $handle, 'asset_type' => 'css', 'device_type' => 'all', 'group_id' => $group, 'source_label' => 'AA Scanner' ];
    }

    private function groups(): array {
        return [ [ 'id' => 1, 'name' => 'AA Scanner - Safe', 'description' => 'safe' ], [ 'id' => 2, 'name' => 'AA Scanner - Aggressive', 'description' => 'agg' ] ];
    }

    /** Fixture A. $scoped: null => key absent; array => key present. */
    private function fixture_a( $scoped = [ 'https://site.test/' ] ): array {
        $rules = [];
        for ( $i = 1; $i <= 6; $i++ )  { $rules[] = $this->rule( 'https://site.test/', "home-$i" ); }
        $rules[] = $this->rule( 'https://site.test/other', 'other-safe', 1 );                  // the S 1 among the carried 13
        for ( $i = 1; $i <= 12; $i++ ) { $rules[] = $this->rule( 'https://site.test/other', "other-$i" ); }
        $json = [ 'version' => 2, 'exported_at' => '2026-09-05T00:00:00+00:00', 'groups' => $this->groups(), 'rules' => $rules, 'by_page' => [ [ 'safe' => 0, 'aggressive' => 6, 'needed' => 0 ] ] ];
        if ( null !== $scoped ) { $json['scanned_patterns'] = $scoped; }
        return $json;
    }

    private function stub( array $json, string $job = 'job-1' ): void {
        $this->options[ 'cu_scanner_json_' . $job ] = json_encode( $json );
        $_POST['job_id'] = $job;
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( true );
        WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
        WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => (string) $v );
        WP_Mock::userFunction( 'absint' )->andReturnUsing( fn( $v ) => abs( (int) $v ) );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://site.test' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
        WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default );
        WP_Mock::userFunction( 'update_option' )->andReturnUsing( function ( $name, $value ) { $this->options[ $name ] = $value; return true; } );
        WP_Mock::userFunction( 'delete_option' )->andReturnUsing( function ( $name ) { unset( $this->options[ $name ] ); return true; } );
        WP_Mock::userFunction( 'wp_send_json_success' )->andReturnUsing( function ( $data ) { $this->captured = $data; } );
        WP_Mock::userFunction( 'wp_send_json_error' )->andReturnUsing( function ( $data ) { $this->error = $data; } );
    }

    private function seed_home_rules( int $n, int $aggressive_group_id ): void {
        for ( $i = 1; $i <= $n; $i++ ) {
            FakeRuleRepository::create_rule( [ 'url_pattern' => 'https://site.test/', 'match_type' => 'exact', 'asset_handle' => "home-$i", 'asset_type' => 'css', 'device_type' => 'all', 'group_id' => $aggressive_group_id, 'source_label' => 'AA Scanner' ] );
        }
    }

    private function cu_patterns(): array { return array_values( array_unique( array_column( FakeRuleRepository::$rules, 'url_pattern' ) ) ); }

    // -------------------------------------------------------------- AC-2
    public function test_ac2a_sync_on_empty_cu_reports_0_6_0(): void {
        $this->stub( $this->fixture_a() );
        ( new ScannerAjax() )->sync_to_cu();
        $this->assertNull( $this->error );
        $this->assertSame( [ 0, 6, 0 ], [ $this->captured['appended_safe'], $this->captured['appended_aggressive'], $this->captured['already_present'] ] );
        $this->assertCount( 6, $this->captured['created_rule_ids'] );
        $this->assertCount( 6, FakeRuleRepository::$rules );
        $this->assertSame( [ 'https://site.test/' ], $this->cu_patterns(), 'only the scanned page\'s pattern reached CU' );
    }

    public function test_ac2b_sync_with_5_present_reports_0_1_5(): void {
        $this->stub( $this->fixture_a() );
        $gid = FakeRuleRepository::create_group( 'AA Scanner - Aggressive', 'agg' );
        $this->seed_home_rules( 5, $gid );
        ( new ScannerAjax() )->sync_to_cu();
        $this->assertSame( [ 0, 1, 5 ], [ $this->captured['appended_safe'], $this->captured['appended_aggressive'], $this->captured['already_present'] ] );
        $this->assertCount( 1, $this->captured['created_rule_ids'] );
    }

    // -------------------------------------------------------------- AC-3
    public function test_ac3_push_replaces_all_with_the_scoped_set(): void {
        $this->stub( $this->fixture_a() );
        $old = FakeRuleRepository::create_group( 'AA Scanner - Aggressive', 'agg' );
        for ( $i = 1; $i <= 13; $i++ ) { FakeRuleRepository::create_rule( [ 'url_pattern' => 'https://site.test/other', 'match_type' => 'exact', 'asset_handle' => "other-$i", 'asset_type' => 'css', 'device_type' => 'all', 'group_id' => $old, 'source_label' => 'AA Scanner' ] ); }
        $_POST['confirmed'] = '1';
        ( new ScannerAjax() )->push_to_cu();
        $this->assertNull( $this->error );
        $this->assertSame( 0, $this->captured['safe_count'] );
        $this->assertSame( 6, $this->captured['aggressive_count'] );
        $this->assertSame( 0, FakeRuleRepository::$updated_groups[ $old ]['enabled'] ?? -1, 'the pre-existing scanner group was disabled (snapshot/commit ran)' );
        $fresh = array_filter( FakeRuleRepository::$rules, fn( $r ) => in_array( $r['group_id'], $this->captured['created_group_ids'], true ) );
        $this->assertCount( 6, $fresh );
        foreach ( $fresh as $r ) { $this->assertSame( 'https://site.test/', $r['url_pattern'] ); }
    }

    // -------------------------------------------------------------- AC-4
    public function test_ac4a_key_absent_keeps_todays_unscoped_counts(): void {
        $this->stub( $this->fixture_a( null ) );
        ( new ScannerAjax() )->sync_to_cu();
        $this->assertSame( [ 1, 18, 0 ], [ $this->captured['appended_safe'], $this->captured['appended_aggressive'], $this->captured['already_present'] ] );
    }

    /** @dataProvider malformed_scalar_keys */
    public function test_ac4b_scalar_key_is_treated_as_absent( $key ): void {
        $this->stub( $this->fixture_a( $key ) );
        ( new ScannerAjax() )->sync_to_cu();
        $this->assertSame( 18, $this->captured['appended_aggressive'] );
    }
    public function malformed_scalar_keys(): array { return [ 'string' => [ 'https://site.test/' ], 'int' => [ 7 ] ]; }

    /**
     * Controller Ruling F (task-2 review, 2026-09-05): split from the former combined
     * test_ac4c_junk_array_is_fail_closed_on_sync_and_push() — the push leg guards the
     * retire-every-scanner-rule blast radius and must report independently of the sync leg.
     * Each leg gets its OWN fixture setup (own $this->stub() call) rather than sharing state.
     * @dataProvider junk_arrays
     */
    public function test_ac4c_sync_fail_closed( array $key ): void {
        $this->stub( $this->fixture_a( $key ) );
        ( new ScannerAjax() )->sync_to_cu();
        $this->assertSame( 'No internal rules to sync', $this->error );
        $this->assertNull( $this->captured );
        $this->assertSame( [], FakeRuleRepository::$rules, 'the pusher was never entered' );
    }

    /** @dataProvider junk_arrays */
    public function test_ac4c_push_fail_closed( array $key ): void {
        // Own fixture, with a pre-seeded ACTIVE scanner rule that must survive.
        $this->stub( $this->fixture_a( $key ) );
        $gid = FakeRuleRepository::create_group( 'AA Scanner - Aggressive', 'agg' );
        $this->seed_home_rules( 1, $gid );
        $_POST['confirmed'] = '1';
        ( new ScannerAjax() )->push_to_cu();
        $this->assertSame( 'No internal rules to push', $this->error );
        $this->assertNull( $this->captured );
        $this->assertCount( 1, FakeRuleRepository::$rules, 'no create_rule ran' );
        $this->assertArrayNotHasKey( $gid, FakeRuleRepository::$updated_groups, 'no group was renamed or disabled — the pusher (snapshot/bump) never ran' );
        $this->assertCount( 1, FakeRuleRepository::$groups, 'no snapshot group and no fresh groups were created' );
    }
    public function junk_arrays(): array { return [ 'ints' => [ [ 123 ] ], 'empty' => [ [] ] ]; }

    // -------------------------------------------------------------- AC-6
    public function test_ac6_undo_removes_exactly_the_scoped_rules_and_disables_the_two_created_groups(): void {
        $this->stub( $this->fixture_a() );
        $other_gid = FakeRuleRepository::create_group( 'Manual', 'kept' );
        FakeRuleRepository::create_rule( [ 'url_pattern' => 'https://site.test/manual', 'match_type' => 'exact', 'asset_handle' => 'manual-a', 'asset_type' => 'css', 'device_type' => 'all', 'group_id' => $other_gid, 'source_label' => 'manual' ] );
        $ajax = new ScannerAjax();
        $ajax->sync_to_cu();
        $this->assertTrue( $this->captured['undo_state']['available'] );
        $this->assertSame( 6, $this->captured['undo_state']['counts']['aggressive'] );
        $created_ids    = $this->captured['created_rule_ids'];
        $created_groups = $this->captured['created_group_ids'];
        $this->assertCount( 2, $created_groups, 'Sync created both scanner groups on an empty CU' );
        $manifest = $this->options['aias_last_push_sync_undo'];
        $this->assertSame( $created_ids, $manifest['rule_ids'] );
        $this->assertSame( $created_groups, $manifest['created_group_ids'] );

        $this->captured = null;
        $ajax->undo_last_push_sync();
        $this->assertSame( 6, $this->captured['deleted_rule_count'] );
        $this->assertSame( 2, $this->captured['disabled_group_count'] );
        $this->assertCount( 1, FakeRuleRepository::$rules, 'the pre-existing manual rule survived' );
        $this->assertSame( 'manual-a', FakeRuleRepository::$rules[0]['asset_handle'] );
        $this->assertArrayNotHasKey( $other_gid, FakeRuleRepository::$updated_groups, 'the pre-existing group was not touched' );
    }

    // -------------------------------------------------------------- AC-8
    public function test_ac8_only_host_match_and_pattern_match_survive(): void {
        $json = $this->fixture_a( [ 'https://site.test/', 'https://ext.test/p' ] );
        $json['rules'][] = $this->rule( 'https://ext.test/p', 'ext-in-scope' );     // pattern in scope, host external => dropped
        $this->stub( $json );
        ( new ScannerAjax() )->sync_to_cu();
        $this->assertSame( 6, $this->captured['appended_aggressive'] );
        $this->assertSame( [ 'https://site.test/' ], $this->cu_patterns() );
    }

    // -------------------------------------------------------------- AC-9 (+ AC-2(c) feed)
    public function test_ac9_json_produced_by_the_real_build_round_trips_to_the_scoped_count(): void {
        // The AC-1 producer test stores JSON via update_option; re-produce it here the same way, then Sync it unmodified.
        $produced = ( new SyncScopeBuildResultTestFixtureAccess() )->et_rescan_json( $this );
        // Non-vacuity guard (P17 review fix): without this, both assertions below pass
        // trivially the moment the producer stops emitting any out-of-scope rule at all —
        // there would be nothing left for filter_scanned_rules() to prove it drops.
        $this->assertNotEmpty( array_filter( $produced['rules'], fn( $r ) => 'https://site.test/' !== $r['url_pattern'] ), 'the produced JSON carried out-of-scope rules for the filter to drop' );
        $this->stub( $produced, 'job-et' );
        ( new ScannerAjax() )->sync_to_cu();
        $this->assertNull( $this->error );
        $this->assertSame( [ 'https://site.test/' ], $this->cu_patterns(), 'the produced JSON scopes to the rescanned page' );
        $this->assertSame( count( array_filter( $produced['rules'], fn( $r ) => 'https://site.test/' === $r['url_pattern'] ) ), $this->captured['appended_aggressive'] + $this->captured['appended_safe'] );
    }
}

<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\RulePusher;
use WP_Mock\Tools\TestCase;

// FakeRuleRepository is defined in SnapshotManagerTest.php.
// PHPUnit loads files alphabetically and RulePusherTest comes first,
// so we require it explicitly to guarantee the class is available.
require_once __DIR__ . '/SnapshotManagerTest.php';

/**
 * Minimal fake for RulePusher integration tests.
 * Re-uses FakeRuleRepository from SnapshotManagerTest — load that file first.
 */
class RulePusherTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        \WP_Mock::setUp();
        // FakeRuleRepository is defined in SnapshotManagerTest.php — loaded by PHPUnit
        FakeRuleRepository::reset();
    }

    public function tearDown(): void {
        \WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_can_push_returns_false_when_cu_not_active(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'code-unloader/code-unloader.php' )
            ->andReturn( false );
        $this->assertFalse( ( new RulePusher() )->can_push() );
    }

    public function test_push_snapshots_active_rules_before_pushing_new_ones(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );

        // Pre-existing active rule in CU
        FakeRuleRepository::$groups = [ [ 'id' => 5, 'name' => 'Old', 'enabled' => 1 ] ];
        FakeRuleRepository::$rules  = [ [ 'id' => 201, 'group_id' => 5, 'url_pattern' => '/old/', 'match_type' => 'exact', 'asset_handle' => 'old-script', 'asset_type' => 'js', 'device_type' => 'all', 'label' => null, 'source_label' => 'manual', 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ] ];

        $pusher = new RulePusher( FakeRuleRepository::class );
        $pusher->push( $this->minimal_cu_json() );

        // A snapshot group should now exist (disabled)
        $snapshot_group = array_values( array_filter(
            FakeRuleRepository::$groups,
            fn( $g ) => str_starts_with( $g['name'], 'Previously active rules' )
        ) )[0] ?? null;
        $this->assertNotNull( $snapshot_group, 'Snapshot group should have been created' );
        $this->assertSame( 0, FakeRuleRepository::$updated_groups[ $snapshot_group['id'] ]['enabled'] ?? -1 );

        // Original group 5 must be disabled (commit ran)
        $this->assertSame( 0, FakeRuleRepository::$updated_groups[5]['enabled'] ?? -1 );
    }

    public function test_push_does_not_disable_old_groups_if_no_active_rules(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );

        // Only a disabled group exists
        FakeRuleRepository::$groups = [ [ 'id' => 5, 'name' => 'Old', 'enabled' => 0 ] ];
        FakeRuleRepository::$rules  = [];

        $pusher = new RulePusher( FakeRuleRepository::class );
        $pusher->push( $this->minimal_cu_json() );

        // No snapshot group should have been created
        $snapshot_group = array_values( array_filter(
            FakeRuleRepository::$groups,
            fn( $g ) => str_starts_with( $g['name'], 'Previously active rules' )
        ) );
        $this->assertEmpty( $snapshot_group );
    }

    public function test_push_returns_correct_counts(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [];
        FakeRuleRepository::$rules  = [];

        $pusher = new RulePusher( FakeRuleRepository::class );
        $stats  = $pusher->push( $this->minimal_cu_json() );

        $this->assertArrayHasKey( 'safe_count',       $stats );
        $this->assertArrayHasKey( 'aggressive_count', $stats );
        $this->assertArrayHasKey( 'error_count',      $stats );
        $this->assertSame( 1, $stats['safe_count'] );
        $this->assertSame( 0, $stats['error_count'] );
    }

    public function test_push_renames_existing_scanner_groups_before_creating_new_ones(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );

        FakeRuleRepository::$groups = [
            [ 'id' => 5, 'name' => 'AA Scanner — Safe',       'enabled' => 0 ],
            [ 'id' => 6, 'name' => 'AA Scanner — Aggressive', 'enabled' => 0 ],
        ];
        FakeRuleRepository::$rules = [];

        ( new RulePusher( FakeRuleRepository::class ) )->push( $this->full_cu_json() );

        $names = array_column( FakeRuleRepository::$groups, 'name' );
        $this->assertContains( 'AA Scanner — Safe v1',       $names, 'Old Safe group must be versioned' );
        $this->assertContains( 'AA Scanner — Aggressive v1', $names, 'Old Aggressive group must be versioned' );
        $this->assertContains( 'AA Scanner — Safe',          $names, 'Fresh Safe group must be created' );
        $this->assertContains( 'AA Scanner — Aggressive',    $names, 'Fresh Aggressive group must be created' );
    }

    public function test_push_enables_both_safe_and_aggressive_groups(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );

        FakeRuleRepository::$groups = [];
        FakeRuleRepository::$rules  = [];

        $stats = ( new RulePusher( FakeRuleRepository::class ) )->push( $this->full_cu_json() );
        $this->assertSame( 0, $stats['error_count'] );

        $safe_group = null;
        $agg_group  = null;
        foreach ( FakeRuleRepository::$groups as $g ) {
            if ( $g['name'] === 'AA Scanner — Safe' )       { $safe_group = $g; }
            if ( $g['name'] === 'AA Scanner — Aggressive' ) { $agg_group  = $g; }
        }
        $this->assertNotNull( $safe_group, 'Safe group must exist after push' );
        $this->assertNotNull( $agg_group,  'Aggressive group must exist after push' );
        $this->assertSame( 1, $safe_group['enabled'], 'Safe group must be enabled' );
        $this->assertSame( 1, $agg_group['enabled'],  'Aggressive group must be enabled (new behavior)' );
    }

    public function test_push_rolls_back_snapshot_when_version_bump_fails(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );

        // Repo where update_group always fails (makes bump_scanner_groups return WP_Error)
        $failing_repo = new class extends FakeRuleRepository {
            public static function update_group( int $id, array $data ): bool {
                return false;
            }
        };
        $failing_repo::reset();
        $failing_repo::$groups = [
            [ 'id' => 10, 'name' => 'Active Manual', 'enabled' => 1 ],
            [ 'id' => 11, 'name' => 'AA Scanner — Safe', 'enabled' => 0 ],
        ];
        $failing_repo::$rules = [
            [ 'id' => 99, 'group_id' => 10, 'url_pattern' => '/home/', 'match_type' => 'exact', 'asset_handle' => 'theme', 'asset_type' => 'css', 'device_type' => 'all', 'label' => null, 'source_label' => 'manual', 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ],
        ];

        $pusher = new RulePusher( $failing_repo::class );
        $stats  = $pusher->push( $this->full_cu_json() );

        // Push must report an error
        $this->assertSame( 1, $stats['error_count'] );
        $this->assertSame( 0, $stats['safe_count'] );

        // Snapshot group created by snapshot() must have been deleted by rollback()
        $snapshot_groups = array_filter(
            $failing_repo::$groups,
            fn( $g ) => str_starts_with( $g['name'], 'Previously active rules' )
        );
        $this->assertEmpty( $snapshot_groups, 'Snapshot group must be rolled back on bump failure' );
    }

    // -------------------------------------------------------------------------
    // sync() tests
    // -------------------------------------------------------------------------

    public function test_sync_creates_groups_and_appends_all_when_none_exist(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [];
        FakeRuleRepository::$rules  = [];

        $stats = ( new RulePusher( FakeRuleRepository::class ) )->sync( $this->full_cu_json() );

        $this->assertSame( 1, $stats['appended_safe'] );
        $this->assertSame( 1, $stats['appended_aggressive'] );
        $this->assertSame( 0, $stats['already_present'] );
        $this->assertSame( 0, $stats['error_count'] );

        $names = array_column( FakeRuleRepository::$groups, 'name' );
        $this->assertContains( 'AA Scanner — Safe', $names );
        $this->assertContains( 'AA Scanner — Aggressive', $names );
        foreach ( FakeRuleRepository::$groups as $g ) {
            $this->assertSame( 1, $g['enabled'], $g['name'] . ' must be enabled after sync' );
        }
        $this->assertEmpty( array_filter( $names, fn( $n ) => str_contains( $n, ' v' ) || str_starts_with( $n, 'Previously active' ) ) );
    }

    public function test_sync_appends_into_existing_groups_without_overwriting(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [
            [ 'id' => 50, 'name' => 'AA Scanner — Safe',       'enabled' => 1 ],
            [ 'id' => 51, 'name' => 'AA Scanner — Aggressive', 'enabled' => 1 ],
        ];
        FakeRuleRepository::$rules = [
            [ 'id' => 9, 'group_id' => 50, 'url_pattern' => 'https://example.com/old', 'match_type' => 'exact', 'asset_handle' => 'pre-existing', 'asset_type' => 'css', 'device_type' => 'all' ],
        ];

        $stats = ( new RulePusher( FakeRuleRepository::class ) )->sync( $this->full_cu_json() );

        $this->assertSame( 1, $stats['appended_safe'] );
        $this->assertSame( 1, $stats['appended_aggressive'] );
        $patterns = array_column( FakeRuleRepository::$rules, 'url_pattern' );
        $this->assertContains( 'https://example.com/old', $patterns, 'pre-existing rule must be untouched' );
        $names = array_column( FakeRuleRepository::$groups, 'name' );
        $this->assertEmpty( array_filter( $names, fn( $n ) => str_contains( $n, ' v' ) || str_starts_with( $n, 'Previously active' ) ) );
    }

    public function test_sync_skips_duplicates_and_does_not_count_them(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [
            [ 'id' => 50, 'name' => 'AA Scanner — Safe',       'enabled' => 1 ],
            [ 'id' => 51, 'name' => 'AA Scanner — Aggressive', 'enabled' => 1 ],
        ];
        FakeRuleRepository::$rules = [
            [ 'id' => 9, 'group_id' => 50, 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-css', 'asset_type' => 'css', 'device_type' => 'all' ],
        ];

        $stats = ( new RulePusher( FakeRuleRepository::class ) )->sync( $this->full_cu_json() );

        $this->assertSame( 0, $stats['appended_safe'], 'duplicate Safe rule must not be counted as appended' );
        $this->assertSame( 1, $stats['appended_aggressive'], 'new Aggressive rule still appends' );
        $this->assertSame( 1, $stats['already_present'], 'the duplicate must be counted as already_present' );
        $this->assertSame( 0, $stats['error_count'] );
    }

    public function test_sync_normalizes_asset_type_before_dedup(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [
            [ 'id' => 50, 'name' => 'AA Scanner — Safe',       'enabled' => 1 ],
            [ 'id' => 51, 'name' => 'AA Scanner — Aggressive', 'enabled' => 1 ],
        ];
        // Stored rule is already normalized to 'css' (the form CU stores).
        FakeRuleRepository::$rules = [
            [ 'id' => 9, 'group_id' => 50, 'url_pattern' => 'https://example.com/x', 'match_type' => 'exact', 'asset_handle' => 'styler', 'asset_type' => 'css', 'device_type' => 'all' ],
        ];
        // Incoming scan rule is the SAME logical rule but with raw 'style' (normalizes to 'css').
        $cu_json = [
            'groups' => [
                [ 'id' => 1, 'name' => 'AA Scanner — Safe',       'description' => '' ],
                [ 'id' => 2, 'name' => 'AA Scanner — Aggressive', 'description' => '' ],
            ],
            'rules' => [
                [ 'url_pattern' => 'https://example.com/x', 'match_type' => 'exact', 'asset_handle' => 'styler', 'asset_type' => 'style', 'device_type' => 'all', 'group_id' => 1, 'source_label' => 'AA Scanner' ],
            ],
        ];

        $stats = ( new RulePusher( FakeRuleRepository::class ) )->sync( $cu_json );

        $this->assertSame( 0, $stats['appended_safe'], "'style' must normalize to 'css' and match the stored rule (not appended)" );
        $this->assertSame( 1, $stats['already_present'], 'normalized duplicate must count as already_present' );
    }

    public function test_sync_re_enables_a_disabled_aggressive_group(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [
            [ 'id' => 50, 'name' => 'AA Scanner — Safe',       'enabled' => 1 ],
            [ 'id' => 51, 'name' => 'AA Scanner — Aggressive', 'enabled' => 0 ],
        ];
        FakeRuleRepository::$rules = [];

        ( new RulePusher( FakeRuleRepository::class ) )->sync( $this->full_cu_json() );

        $by_name = array_column( FakeRuleRepository::$groups, 'enabled', 'name' );
        $this->assertSame( 1, $by_name['AA Scanner — Safe'] );
        $this->assertSame( 1, $by_name['AA Scanner — Aggressive'], 'Sync must re-enable a disabled Aggressive group' );
    }

    public function test_sync_rollback_deletes_only_this_run_inserts_on_error(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );

        $failing_repo = new class extends FakeRuleRepository {
            public static int $n = 0;
            public static function create_rule( array $data ): int|\WP_Error {
                self::$n++;
                if ( self::$n === 2 ) { return new \WP_Error( 'db_error', 'boom' ); }
                return parent::create_rule( $data );
            }
            public static function reset(): void { self::$n = 0; parent::reset(); }
        };
        $failing_repo::reset();
        $failing_repo::$groups = [
            [ 'id' => 50, 'name' => 'AA Scanner — Safe',       'enabled' => 1 ],
            [ 'id' => 51, 'name' => 'AA Scanner — Aggressive', 'enabled' => 1 ],
        ];
        $failing_repo::$rules = [
            [ 'id' => 9, 'group_id' => 50, 'url_pattern' => 'https://example.com/keep', 'match_type' => 'exact', 'asset_handle' => 'keep', 'asset_type' => 'css', 'device_type' => 'all' ],
        ];

        $stats = ( new RulePusher( $failing_repo::class ) )->sync( $this->full_cu_json() );

        $this->assertSame( 1, $stats['error_count'] );
        $this->assertSame( 0, $stats['appended_safe'] );
        $this->assertSame( 0, $stats['appended_aggressive'] );
        $this->assertNotEmpty( $failing_repo::$deleted_rule_ids, 'this-run insert must be rolled back' );
        $this->assertNotContains( 9, $failing_repo::$deleted_rule_ids, 'pre-existing rule must NOT be deleted' );
    }

    // -------------------------------------------------------------------------

    public function test_has_active_cu_rules_true_when_enabled_group_has_rules(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        FakeRuleRepository::$groups = [ [ 'id' => 1, 'name' => 'G', 'enabled' => 1 ] ];
        FakeRuleRepository::$rules  = [ [ 'id' => 9, 'group_id' => 1, 'url_pattern' => '/x/', 'match_type' => 'exact', 'asset_handle' => 'h', 'asset_type' => 'js', 'device_type' => 'all' ] ];
        $this->assertTrue( ( new RulePusher( FakeRuleRepository::class ) )->has_active_cu_rules() );
    }

    public function test_has_active_cu_rules_false_when_no_enabled_rules(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        FakeRuleRepository::$groups = [ [ 'id' => 1, 'name' => 'G', 'enabled' => 0 ] ];
        FakeRuleRepository::$rules  = [ [ 'id' => 9, 'group_id' => 1, 'url_pattern' => '/x/', 'match_type' => 'exact', 'asset_handle' => 'h', 'asset_type' => 'js', 'device_type' => 'all' ] ];
        $this->assertFalse( ( new RulePusher( FakeRuleRepository::class ) )->has_active_cu_rules() );
    }

    private function minimal_cu_json(): array {
        return [
            'groups' => [
                [ 'id' => 1, 'name' => 'AA Scanner — Safe',       'description' => '' ],
                [ 'id' => 2, 'name' => 'AA Scanner — Aggressive', 'description' => '' ],
            ],
            'rules' => [
                [ 'url_pattern' => 'https://example.com/home', 'match_type' => 'exact', 'asset_handle' => 'my-js', 'asset_type' => 'js', 'device_type' => 'all', 'group_id' => 1, 'source_label' => 'AA Scanner' ],
            ],
        ];
    }

    private function full_cu_json(): array {
        return [
            'groups' => [
                [ 'id' => 1, 'name' => 'AA Scanner — Safe',       'description' => '' ],
                [ 'id' => 2, 'name' => 'AA Scanner — Aggressive', 'description' => '' ],
            ],
            'rules' => [
                [ 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-css', 'asset_type' => 'css', 'device_type' => 'all', 'group_id' => 1, 'source_label' => 'AA Scanner' ],
                [ 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-js',  'asset_type' => 'js',  'device_type' => 'all', 'group_id' => 2, 'source_label' => 'AA Scanner' ],
            ],
        ];
    }

    // ------------------------------------------------------------------ FU-AAS-SYNC-DEVICE-DUPLICATES (spec §5 AC-1 / AC-4 / AC-5)

    /** The real scanner-group names (r2 Major): taken from the builder, asserted to carry the em dash. */
    private function production_groups(): array {
        \WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( (string) $u, $c ) );
        $groups = ( new \CUScanner\Scanner\CuJsonBuilder() )->build( [ [ 'url' => 'https://site.test/', 'status' => 'done', 'assets' => [] ] ], [] )['groups'];
        $this->assertStringContainsString( "\u{2014}", $groups[1]['name'], 'production group names carry an em dash (U+2014)' );
        return $groups;
    }

    /** Seeds both scanner groups (Safe id 50, Aggressive id 51) under the PRODUCTION names; returns [safe_id, aggressive_id]. */
    private function seed_groups_for_coverage(): array {
        $groups = $this->production_groups();
        FakeRuleRepository::$groups = [
            [ 'id' => 50, 'name' => $groups[0]['name'], 'enabled' => 1 ],
            [ 'id' => 51, 'name' => $groups[1]['name'], 'enabled' => 1 ],
        ];
        return [ 50, 51 ];
    }

    private function cu_row( string $handle, ?string $device, int $group_id, string $pattern = 'https://site.test/', string $type = 'css' ): array {
        return [ 'id' => 200 + count( FakeRuleRepository::$rules ), 'group_id' => $group_id, 'url_pattern' => $pattern, 'match_type' => 'exact', 'asset_handle' => $handle, 'asset_type' => $type, 'device_type' => $device ];
    }

    private function scan_rule( string $handle, string $device, int $group = 2, string $pattern = 'https://site.test/', string $type = 'css' ): array {
        return [ 'url_pattern' => $pattern, 'match_type' => 'exact', 'asset_handle' => $handle, 'asset_type' => $type, 'device_type' => $device, 'group_id' => $group, 'source_label' => 'AA Scanner' ];
    }

    private function coverage_json( array $rules ): array {
        return [ 'groups' => $this->production_groups(), 'rules' => $rules ];
    }

    private function run_sync( array $rules ): array {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        return ( new RulePusher( FakeRuleRepository::class ) )->sync( $this->coverage_json( $rules ) );
    }

    /**
     * AC-1 truth table. Each row: [ seeded CU rows (device list, all same 5-key), rule device to send, expect_present ].
     * @dataProvider coverage_rows
     */
    public function test_ac1_coverage_subset_truth_table( array $seed_devices, string $send_device, bool $expect_present, string $why ): void {
        [ , $agg ] = $this->seed_groups_for_coverage();
        foreach ( $seed_devices as $d ) { FakeRuleRepository::$rules[] = $this->cu_row( 'h', $d, $agg ); }
        $before = count( FakeRuleRepository::$rules );

        $stats = $this->run_sync( [ $this->scan_rule( 'h', $send_device ) ] );

        $this->assertSame( 0, $stats['error_count'], $why );
        $this->assertSame( $expect_present ? 1 : 0, $stats['already_present'], $why );
        $this->assertSame( $expect_present ? 0 : 1, $stats['appended_aggressive'], $why );
        $this->assertCount( $expect_present ? 0 : 1, $stats['created_rule_ids'], $why );
        $this->assertCount( $before + ( $expect_present ? 0 : 1 ), FakeRuleRepository::$rules, $why );
    }
    public function coverage_rows(): array {
        return [
            'desktop vs {all}'              => [ [ 'all' ],               'desktop', true,  'an All row unloads desktop too' ],
            'desktop vs {desktop}'          => [ [ 'desktop' ],           'desktop', true,  'exact match' ],
            'mobile vs {all}'               => [ [ 'all' ],               'mobile',  true,  'an All row unloads mobile too' ],
            'all vs {all}'                  => [ [ 'all' ],               'all',     true,  'exact match' ],
            'all vs {desktop, mobile}'      => [ [ 'desktop', 'mobile' ], 'all',     true,  'both legs together cover All' ],
            'desktop vs {mobile}'           => [ [ 'mobile' ],            'desktop', false, 'F-MISS: desktop is not unloaded' ],
            'all vs {desktop} alone'        => [ [ 'desktop' ],           'all',     false, 'F-MISS: mobile is not unloaded' ],
            'all vs {mobile} alone'         => [ [ 'mobile' ],            'all',     false, 'F-MISS: desktop is not unloaded' ],
            'unknown device vs {all}'       => [ [ 'all' ],               'tablet',  false, 'empty needed set is never covered (spec §3.1)' ],
            'NULL row counts as all (fake-only guard; CU column is NOT NULL)' => [ [ null ], 'desktop', true, "find_duplicate's ?? 'all' on the row side" ],
        ];
    }

    /** AC-1 negatives that vary something OTHER than device: group, pattern, type. Each must insert. */
    public function test_ac1_other_group_pattern_or_type_never_covers(): void {
        [ $safe, $agg ] = $this->seed_groups_for_coverage();
        FakeRuleRepository::$groups[] = [ 'id' => 60, 'name' => 'Customer group', 'enabled' => 1 ];
        FakeRuleRepository::$rules = [
            $this->cu_row( 'h-group',   'all', $safe ),                                  // same key in the OTHER scanner group
            $this->cu_row( 'h-cust',    'all', 60 ),                                     // same key in a non-scanner group
            $this->cu_row( 'h-pattern', 'all', $agg, 'https://site.test/other' ),        // other url_pattern
            $this->cu_row( 'h-type',    'all', $agg, 'https://site.test/', 'js' ),       // other asset_type
        ];
        $stats = $this->run_sync( [
            $this->scan_rule( 'h-group', 'desktop' ), $this->scan_rule( 'h-cust', 'desktop' ),
            $this->scan_rule( 'h-pattern', 'desktop' ), $this->scan_rule( 'h-type', 'desktop' ),
        ] );
        $this->assertSame( [ 0, 4, 0 ], [ $stats['appended_safe'], $stats['appended_aggressive'], $stats['already_present'] ] );
        $this->assertCount( 4, $stats['created_rule_ids'] );
    }

    /** AC-4 — in-list interplay: the probes see THIS run's inserts. */
    public function test_ac4_desktop_and_mobile_legs_then_all_in_one_list(): void {
        $this->seed_groups_for_coverage();
        $stats = $this->run_sync( [ $this->scan_rule( 'h', 'desktop' ), $this->scan_rule( 'h', 'mobile' ), $this->scan_rule( 'h', 'all' ) ] );
        $this->assertSame( [ 0, 2, 1 ], [ $stats['appended_safe'], $stats['appended_aggressive'], $stats['already_present'] ] );
        $this->assertCount( 2, FakeRuleRepository::$rules, 'the two legs only — the All was covered by them' );
    }
    public function test_ac4_same_exact_rule_twice_counts_once_and_records_one_id(): void {
        $this->seed_groups_for_coverage();
        $stats = $this->run_sync( [ $this->scan_rule( 'h', 'desktop' ), $this->scan_rule( 'h', 'desktop' ) ] );
        $this->assertSame( [ 0, 1, 1 ], [ $stats['appended_safe'], $stats['appended_aggressive'], $stats['already_present'] ] );
        $this->assertCount( 1, $stats['created_rule_ids'], 'the exact gate: the repeat is present, its pre-existing id is NOT recorded twice' );
        $this->assertCount( 1, array_unique( $stats['created_rule_ids'] ) );
    }
    public function test_ac4_desktop_then_all_inserts_both(): void {
        $this->seed_groups_for_coverage();
        $stats = $this->run_sync( [ $this->scan_rule( 'h', 'desktop' ), $this->scan_rule( 'h', 'all' ) ] );
        $this->assertSame( [ 0, 2, 0 ], [ $stats['appended_safe'], $stats['appended_aggressive'], $stats['already_present'] ] );
        $this->assertCount( 2, FakeRuleRepository::$rules, 'All is not covered by Desktop alone' );
    }

    /** AC-5 (write-path leg): a repository WITHOUT get_all_rules is NOT degraded — sync() needs only find_duplicate/create_rule/get_all_groups. */
    public function test_ac5_sync_needs_no_bulk_read(): void {
        // The "floor" is modelled observably: a double that INHERITS the fake but records any
        // get_all_rules() call. The write path must be covered through find_duplicate alone.
        $repo = new class extends FakeRuleRepository {
            public static bool $bulk_called = false;
            public static function get_all_rules(): array { self::$bulk_called = true; return parent::get_all_rules(); }
        };
        [ , $agg ] = $this->seed_groups_for_coverage();
        FakeRuleRepository::$rules = [ $this->cu_row( 'h', 'all', $agg ) ];
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );

        $stats = ( new RulePusher( get_class( $repo ) ) )->sync( $this->coverage_json( [ $this->scan_rule( 'h', 'desktop' ) ] ) );

        $this->assertSame( 1, $stats['already_present'], 'covered through find_duplicate probes alone' );
        $this->assertFalse( $repo::$bulk_called, 'sync() must not read get_all_rules (spec §3.2 — the bulk read is advisory-only)' );
    }
}

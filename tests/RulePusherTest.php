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
}

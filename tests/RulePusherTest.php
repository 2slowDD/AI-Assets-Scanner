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
            [ 'id' => 5, 'name' => 'CU Scanner — Safe',       'enabled' => 0 ],
            [ 'id' => 6, 'name' => 'CU Scanner — Aggressive', 'enabled' => 0 ],
        ];
        FakeRuleRepository::$rules = [];

        ( new RulePusher( FakeRuleRepository::class ) )->push( $this->full_cu_json() );

        $names = array_column( FakeRuleRepository::$groups, 'name' );
        $this->assertContains( 'CU Scanner — Safe v1',       $names, 'Old Safe group must be versioned' );
        $this->assertContains( 'CU Scanner — Aggressive v1', $names, 'Old Aggressive group must be versioned' );
        $this->assertContains( 'CU Scanner — Safe',          $names, 'Fresh Safe group must be created' );
        $this->assertContains( 'CU Scanner — Aggressive',    $names, 'Fresh Aggressive group must be created' );
    }

    public function test_push_creates_safe_group_enabled_and_aggressive_group_disabled(): void {
        \WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );

        FakeRuleRepository::$groups = [];
        FakeRuleRepository::$rules  = [];

        $stats = ( new RulePusher( FakeRuleRepository::class ) )->push( $this->full_cu_json() );

        $this->assertSame( 0, $stats['error_count'] );

        $safe_group = null;
        $agg_group  = null;
        foreach ( FakeRuleRepository::$groups as $g ) {
            if ( $g['name'] === 'CU Scanner — Safe' )       { $safe_group = $g; }
            if ( $g['name'] === 'CU Scanner — Aggressive' ) { $agg_group  = $g; }
        }

        $this->assertNotNull( $safe_group, 'Safe group must exist after push' );
        $this->assertNotNull( $agg_group,  'Aggressive group must exist after push' );
        $this->assertSame( 1, $safe_group['enabled'],  'Safe group must be enabled' );
        $this->assertSame( 0, $agg_group['enabled'],   'Aggressive group must be disabled' );
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
            [ 'id' => 11, 'name' => 'CU Scanner — Safe', 'enabled' => 0 ],
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

    private function minimal_cu_json(): array {
        return [
            'groups' => [
                [ 'id' => 1, 'name' => 'CU Scanner — Safe',       'description' => '' ],
                [ 'id' => 2, 'name' => 'CU Scanner — Aggressive', 'description' => '' ],
            ],
            'rules' => [
                [ 'url_pattern' => 'https://example.com/home', 'match_type' => 'exact', 'asset_handle' => 'my-js', 'asset_type' => 'js', 'device_type' => 'all', 'group_id' => 1, 'source_label' => 'CU Scanner' ],
            ],
        ];
    }

    private function full_cu_json(): array {
        return [
            'groups' => [
                [ 'id' => 1, 'name' => 'CU Scanner — Safe',       'description' => '' ],
                [ 'id' => 2, 'name' => 'CU Scanner — Aggressive', 'description' => '' ],
            ],
            'rules' => [
                [ 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-css', 'asset_type' => 'css', 'device_type' => 'all', 'group_id' => 1, 'source_label' => 'CU Scanner' ],
                [ 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-js',  'asset_type' => 'js',  'device_type' => 'all', 'group_id' => 2, 'source_label' => 'CU Scanner' ],
            ],
        ];
    }
}

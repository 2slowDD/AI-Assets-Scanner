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
}

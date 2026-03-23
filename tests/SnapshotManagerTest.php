<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\SnapshotManager;
use WP_Mock\Tools\TestCase;

// ---------------------------------------------------------------------------
// Fake RuleRepository — in-memory test double, no WordPress DB needed
// ---------------------------------------------------------------------------
class FakeRuleRepository {
    public static array $groups = [];
    public static array $rules  = [];
    public static array $deleted_rule_ids  = [];
    public static array $deleted_group_ids = [];
    public static array $updated_groups    = []; // [ id => data ]

    public static function reset(): void {
        self::$groups = self::$rules = self::$deleted_rule_ids
            = self::$deleted_group_ids = self::$updated_groups = [];
    }

    public static function get_all_groups(): array {
        return array_map( fn( $g ) => (object) $g, self::$groups );
    }

    public static function get_all_rules(): array {
        // Return rules joined with their group's enabled flag
        $group_map = array_column( self::$groups, null, 'id' );
        return array_map( function ( $r ) use ( $group_map ) {
            $g = $group_map[ $r['group_id'] ] ?? null;
            $r['group_enabled'] = $g ? $g['enabled'] : 0;
            return (object) $r;
        }, self::$rules );
    }

    public static function create_group( string $name, string $description = '' ): int|\WP_Error {
        $id = count( self::$groups ) + 100; // arbitrary ID
        self::$groups[] = [ 'id' => $id, 'name' => $name, 'description' => $description, 'enabled' => 1 ];
        return $id;
    }

    public static function update_group( int $id, array $data ): bool {
        self::$updated_groups[ $id ] = $data;
        foreach ( self::$groups as &$g ) {
            if ( $g['id'] === $id ) { $g = array_merge( $g, $data ); return true; }
        }
        return false;
    }

    public static function create_rule( array $data ): int|\WP_Error {
        $id = count( self::$rules ) + 200;
        self::$rules[] = array_merge( $data, [ 'id' => $id ] );
        return $id;
    }

    public static function delete_rule( int $id ): bool {
        self::$deleted_rule_ids[] = $id;
        self::$rules = array_values( array_filter( self::$rules, fn( $r ) => $r['id'] !== $id ) );
        return true;
    }

    public static function delete_group( int $id ): bool {
        self::$deleted_group_ids[] = $id;
        self::$groups = array_values( array_filter( self::$groups, fn( $g ) => $g['id'] !== $id ) );
        return true;
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------
class SnapshotManagerTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        \WP_Mock::setUp();
        FakeRuleRepository::reset();
    }

    public function tearDown(): void {
        \WP_Mock::tearDown();
        parent::tearDown();
    }

    private function make_manager(): SnapshotManager {
        return new SnapshotManager( FakeRuleRepository::class );
    }

    // --- has_active_rules() ---

    public function test_has_active_rules_returns_false_when_no_groups(): void {
        $this->assertFalse( $this->make_manager()->has_active_rules() );
    }

    public function test_has_active_rules_returns_false_when_all_groups_disabled(): void {
        FakeRuleRepository::$groups = [ [ 'id' => 1, 'name' => 'G', 'enabled' => 0 ] ];
        FakeRuleRepository::$rules  = [ [ 'id' => 201, 'group_id' => 1, 'url_pattern' => '/foo/', 'match_type' => 'exact', 'asset_handle' => 'h', 'asset_type' => 'js', 'device_type' => 'all' ] ];
        $this->assertFalse( $this->make_manager()->has_active_rules() );
    }

    public function test_has_active_rules_returns_true_when_enabled_group_has_rules(): void {
        FakeRuleRepository::$groups = [ [ 'id' => 1, 'name' => 'G', 'enabled' => 1 ] ];
        FakeRuleRepository::$rules  = [ [ 'id' => 201, 'group_id' => 1, 'url_pattern' => '/foo/', 'match_type' => 'exact', 'asset_handle' => 'h', 'asset_type' => 'js', 'device_type' => 'all' ] ];
        $this->assertTrue( $this->make_manager()->has_active_rules() );
    }

    // --- snapshot() ---

    public function test_snapshot_creates_disabled_group_with_dated_name(): void {
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [ [ 'id' => 1, 'name' => 'Old Group', 'enabled' => 1 ] ];
        FakeRuleRepository::$rules  = [ [ 'id' => 201, 'group_id' => 1, 'url_pattern' => '/foo/', 'match_type' => 'exact', 'asset_handle' => 'my-script', 'asset_type' => 'js', 'device_type' => 'all', 'label' => null, 'source_label' => '', 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ] ];

        $result = $this->make_manager()->snapshot();

        $this->assertTrue( $result );

        // A new group should have been created
        $new_group = end( FakeRuleRepository::$groups );
        $this->assertStringStartsWith( 'Previously active rules ', $new_group['name'] );

        // That group must have been immediately disabled
        $this->assertArrayHasKey( $new_group['id'], FakeRuleRepository::$updated_groups );
        $this->assertSame( 0, FakeRuleRepository::$updated_groups[ $new_group['id'] ]['enabled'] );
    }

    public function test_snapshot_copies_all_active_rule_fields_into_snapshot_group(): void {
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [ [ 'id' => 1, 'name' => 'G', 'enabled' => 1 ] ];
        FakeRuleRepository::$rules  = [ [ 'id' => 201, 'group_id' => 1, 'url_pattern' => '/about/', 'match_type' => 'exact', 'asset_handle' => 'theme-css', 'asset_type' => 'css', 'device_type' => 'desktop', 'label' => 'my label', 'source_label' => 'manual', 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ] ];

        $this->make_manager()->snapshot();

        // The snapshot group was the newly created group (id 100 in FakeRuleRepository)
        $snapshot_group_id = FakeRuleRepository::$groups[1]['id']; // second group
        $copied_rule = array_values( array_filter(
            FakeRuleRepository::$rules,
            fn( $r ) => ( $r['group_id'] ?? null ) === $snapshot_group_id
        ) )[0];

        $this->assertSame( '/about/', $copied_rule['url_pattern'] );
        $this->assertSame( 'exact',   $copied_rule['match_type'] );
        $this->assertSame( 'theme-css', $copied_rule['asset_handle'] );
        $this->assertSame( 'css',     $copied_rule['asset_type'] );
        $this->assertSame( 'desktop', $copied_rule['device_type'] );
        $this->assertSame( 'my label', $copied_rule['label'] );
        $this->assertSame( 'CU Scanner Snapshot', $copied_rule['source_label'] );
    }

    public function test_snapshot_returns_wp_error_when_create_group_fails(): void {
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [ [ 'id' => 1, 'name' => 'G', 'enabled' => 1 ] ];
        FakeRuleRepository::$rules  = [ [ 'id' => 201, 'group_id' => 1, 'url_pattern' => '/x/', 'match_type' => 'exact', 'asset_handle' => 'h', 'asset_type' => 'js', 'device_type' => 'all', 'label' => null, 'source_label' => '', 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ] ];

        // Make create_group return WP_Error by overriding via anonymous class trick:
        // We swap the repo to a version that fails on create_group
        $failing_repo = new class extends FakeRuleRepository {
            public static function create_group( string $name, string $description = '' ): int|\WP_Error {
                return new \WP_Error( 'db_error', 'Insert failed' );
            }
        };

        $manager = new \CUScanner\Scanner\SnapshotManager( $failing_repo::class );
        $result  = $manager->snapshot();

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_snapshot_rollback_cleans_up_partial_copy_on_create_rule_failure(): void {
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );

        // Create a repo variant where the second create_rule() call fails
        $failing_repo = new class extends FakeRuleRepository {
            public static int $call_count = 0;
            public static function create_rule( array $data ): int|\WP_Error {
                self::$call_count++;
                if ( self::$call_count >= 2 ) {
                    return new \WP_Error( 'db_error', 'Second insert failed' );
                }
                return parent::create_rule( $data );
            }
            public static function reset(): void {
                self::$call_count = 0;
                parent::reset();
            }
        };
        $failing_repo::reset();
        FakeRuleRepository::$groups = [ [ 'id' => 1, 'name' => 'G', 'enabled' => 1 ] ];
        FakeRuleRepository::$rules  = [
            [ 'id' => 10, 'group_id' => 1, 'url_pattern' => '/a/', 'match_type' => 'exact', 'asset_handle' => 'h1', 'asset_type' => 'js', 'device_type' => 'all', 'label' => null, 'source_label' => '', 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ],
            [ 'id' => 11, 'group_id' => 1, 'url_pattern' => '/b/', 'match_type' => 'exact', 'asset_handle' => 'h2', 'asset_type' => 'css', 'device_type' => 'all', 'label' => null, 'source_label' => '', 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ],
        ];

        $manager = new \CUScanner\Scanner\SnapshotManager( $failing_repo::class );
        $result  = $manager->snapshot();

        // snapshot() must return WP_Error
        $this->assertInstanceOf( \WP_Error::class, $result );

        // Caller calls rollback() — must clean up the one rule that WAS inserted + the group
        $manager->rollback();

        $this->assertNotEmpty( $failing_repo::$deleted_rule_ids, 'Partial snapshot rule must be deleted by rollback' );
        $this->assertNotEmpty( $failing_repo::$deleted_group_ids, 'Snapshot group must be deleted by rollback' );
        // Original rules must NOT be deleted
        $this->assertNotContains( 10, $failing_repo::$deleted_rule_ids );
        $this->assertNotContains( 11, $failing_repo::$deleted_rule_ids );
    }

    // --- rollback() ---

    public function test_rollback_deletes_inserted_rules_before_deleting_group(): void {
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [ [ 'id' => 1, 'name' => 'G', 'enabled' => 1 ] ];
        // Use id 99 — avoids collision with FakeRuleRepository::create_rule() which produces
        // count(rules)+200. With one rule already present, next create_rule() produces 1+200=201.
        FakeRuleRepository::$rules  = [ [ 'id' => 99, 'group_id' => 1, 'url_pattern' => '/foo/', 'match_type' => 'exact', 'asset_handle' => 'h', 'asset_type' => 'js', 'device_type' => 'all', 'label' => null, 'source_label' => '', 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ] ];

        $manager = $this->make_manager();
        $manager->snapshot();
        $manager->rollback();

        // Some snapshot rule ID must have been deleted (we don't hardcode it — fake ID is count-based)
        $this->assertNotEmpty( FakeRuleRepository::$deleted_rule_ids );
        // The original pre-existing rule (id 99) must NOT be deleted
        $this->assertNotContains( 99, FakeRuleRepository::$deleted_rule_ids );
        // The snapshot group must be deleted
        $this->assertNotEmpty( FakeRuleRepository::$deleted_group_ids );
        // The original group (id 1) must NOT be deleted
        $this->assertNotContains( 1, FakeRuleRepository::$deleted_group_ids );
    }

    public function test_rollback_does_not_touch_pre_existing_groups(): void {
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [ [ 'id' => 5, 'name' => 'Manual', 'enabled' => 1 ] ];
        FakeRuleRepository::$rules  = [ [ 'id' => 99, 'group_id' => 5, 'url_pattern' => '/x/', 'match_type' => 'exact', 'asset_handle' => 'h', 'asset_type' => 'css', 'device_type' => 'all', 'label' => null, 'source_label' => '', 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ] ];

        $manager = $this->make_manager();
        $manager->snapshot();
        $manager->rollback();

        // Pre-existing group 5 must NOT appear in disabled updates after rollback
        if ( isset( FakeRuleRepository::$updated_groups[5] ) ) {
            $this->assertNotSame( 0, FakeRuleRepository::$updated_groups[5]['enabled'] ?? 1,
                'Pre-existing group should not be disabled by rollback' );
        }
        // Group 5 must still be enabled (not deleted)
        $remaining = array_column( FakeRuleRepository::$groups, 'enabled', 'id' );
        $this->assertSame( 1, $remaining[5] ?? null );
    }

    public function test_rollback_is_safe_when_snapshot_was_never_called(): void {
        $manager = $this->make_manager();
        $manager->rollback(); // must not throw
        $this->assertEmpty( FakeRuleRepository::$deleted_rule_ids );
        $this->assertEmpty( FakeRuleRepository::$deleted_group_ids );
    }

    // --- commit() ---

    public function test_snapshot_stores_enabled_group_ids_for_later_commit(): void {
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [
            [ 'id' => 10, 'name' => 'Group A', 'enabled' => 1 ],
            [ 'id' => 20, 'name' => 'Group B', 'enabled' => 0 ],
        ];
        FakeRuleRepository::$rules = [
            [ 'id' => 99, 'group_id' => 10, 'url_pattern' => '/a/', 'match_type' => 'exact', 'asset_handle' => 'h', 'asset_type' => 'js', 'device_type' => 'all', 'label' => null, 'source_label' => '', 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ],
        ];

        $manager = $this->make_manager();
        $manager->snapshot();
        $manager->commit();

        // Group 10 (was enabled) should be disabled after commit
        $this->assertArrayHasKey( 10, FakeRuleRepository::$updated_groups );
        $this->assertSame( 0, FakeRuleRepository::$updated_groups[10]['enabled'] );
        // Group 20 (was disabled at snapshot time) must NOT be touched by commit
        $snapshot_group_id = FakeRuleRepository::$groups[ array_key_last( FakeRuleRepository::$groups ) ]['id'];
        foreach ( FakeRuleRepository::$updated_groups as $id => $data ) {
            if ( $id === $snapshot_group_id ) continue; // snapshot group disable is expected
            if ( $id === 10 ) continue;                 // this one IS expected
            $this->fail( "Unexpected group $id was updated during commit" );
        }
    }

    public function test_commit_disables_all_groups_that_were_enabled_at_snapshot_time(): void {
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [
            [ 'id' => 10, 'name' => 'Active A', 'enabled' => 1 ],
            [ 'id' => 20, 'name' => 'Active B', 'enabled' => 1 ],
            [ 'id' => 30, 'name' => 'Already Off', 'enabled' => 0 ],
        ];
        FakeRuleRepository::$rules = [
            [ 'id' => 201, 'group_id' => 10, 'url_pattern' => '/a/', 'match_type' => 'exact', 'asset_handle' => 'h', 'asset_type' => 'js', 'device_type' => 'all', 'label' => null, 'source_label' => '', 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ],
        ];

        $manager = $this->make_manager();
        $manager->snapshot();
        $manager->commit();

        // Groups 10 and 20 were enabled at snapshot time — both must be disabled
        $this->assertArrayHasKey( 10, FakeRuleRepository::$updated_groups );
        $this->assertSame( 0, FakeRuleRepository::$updated_groups[10]['enabled'] );
        $this->assertArrayHasKey( 20, FakeRuleRepository::$updated_groups );
        $this->assertSame( 0, FakeRuleRepository::$updated_groups[20]['enabled'] );
    }

    public function test_commit_does_not_disable_groups_that_were_already_disabled(): void {
        \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        FakeRuleRepository::$groups = [
            [ 'id' => 10, 'name' => 'Active', 'enabled' => 1 ],
            [ 'id' => 30, 'name' => 'Already Off', 'enabled' => 0 ],
        ];
        FakeRuleRepository::$rules = [
            [ 'id' => 201, 'group_id' => 10, 'url_pattern' => '/a/', 'match_type' => 'exact', 'asset_handle' => 'h', 'asset_type' => 'js', 'device_type' => 'all', 'label' => null, 'source_label' => '', 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ],
        ];

        $manager = $this->make_manager();
        $manager->snapshot();
        $manager->commit();

        // Group 30 was NOT enabled at snapshot time — must not appear in updates
        $this->assertArrayNotHasKey( 30, FakeRuleRepository::$updated_groups );
    }
}

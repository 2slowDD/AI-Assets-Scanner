<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\GroupVersionManager;
use WP_Mock\Tools\TestCase;

require_once __DIR__ . '/SnapshotManagerTest.php'; // provides FakeRuleRepository

class GroupVersionManagerTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        \WP_Mock::setUp();
        FakeRuleRepository::reset();
    }

    public function tearDown(): void {
        \WP_Mock::tearDown();
        parent::tearDown();
    }

    private function make_manager(): GroupVersionManager {
        return new GroupVersionManager( FakeRuleRepository::class );
    }

    // --- No existing scanner groups ---

    public function test_bump_returns_true_when_no_scanner_groups_exist(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 1, 'name' => 'Manual Rules', 'enabled' => 1 ],
        ];
        $result = $this->make_manager()->bump_scanner_groups();
        $this->assertTrue( $result );
        // Non-scanner group must be untouched
        $this->assertSame( 'Manual Rules', FakeRuleRepository::$groups[0]['name'] );
        $this->assertSame( 1, FakeRuleRepository::$groups[0]['enabled'] );
    }

    public function test_bump_returns_true_when_groups_array_is_empty(): void {
        FakeRuleRepository::$groups = [];
        $result = $this->make_manager()->bump_scanner_groups();
        $this->assertTrue( $result );
    }

    // --- Safe group versioning ---

    public function test_bump_renames_safe_group_to_v1_when_no_prior_versions_exist(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 10, 'name' => 'CU Scanner — Safe', 'enabled' => 1 ],
        ];
        $result = $this->make_manager()->bump_scanner_groups();
        $this->assertTrue( $result );
        $g = FakeRuleRepository::$groups[0];
        $this->assertSame( 'CU Scanner — Safe v1', $g['name'] );
        $this->assertSame( 0, $g['enabled'] );
    }

    public function test_bump_renames_safe_group_to_v2_when_v1_already_exists(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 5,  'name' => 'CU Scanner — Safe v1', 'enabled' => 0 ],
            [ 'id' => 10, 'name' => 'CU Scanner — Safe',    'enabled' => 1 ],
        ];
        $result = $this->make_manager()->bump_scanner_groups();
        $this->assertTrue( $result );
        $base = null;
        foreach ( FakeRuleRepository::$groups as $g ) {
            if ( $g['id'] === 10 ) { $base = $g; break; }
        }
        $this->assertSame( 'CU Scanner — Safe v2', $base['name'] );
        $this->assertSame( 0, $base['enabled'] );
    }

    public function test_bump_uses_highest_existing_version_not_count(): void {
        // v1 and v3 exist (v2 is missing) — next must be v4
        FakeRuleRepository::$groups = [
            [ 'id' => 3,  'name' => 'CU Scanner — Safe v1', 'enabled' => 0 ],
            [ 'id' => 4,  'name' => 'CU Scanner — Safe v3', 'enabled' => 0 ],
            [ 'id' => 10, 'name' => 'CU Scanner — Safe',    'enabled' => 1 ],
        ];
        $this->make_manager()->bump_scanner_groups();
        $base = null;
        foreach ( FakeRuleRepository::$groups as $g ) {
            if ( $g['id'] === 10 ) { $base = $g; break; }
        }
        $this->assertSame( 'CU Scanner — Safe v4', $base['name'] );
    }

    // --- Aggressive group versioning ---

    public function test_bump_renames_aggressive_group_to_v1(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 20, 'name' => 'CU Scanner — Aggressive', 'enabled' => 1 ],
        ];
        $this->make_manager()->bump_scanner_groups();
        $this->assertSame( 'CU Scanner — Aggressive v1', FakeRuleRepository::$groups[0]['name'] );
        $this->assertSame( 0, FakeRuleRepository::$groups[0]['enabled'] );
    }

    // --- Both groups together ---

    public function test_bump_renames_both_safe_and_aggressive_independently(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 5,  'name' => 'CU Scanner — Safe v1',       'enabled' => 0 ],
            [ 'id' => 10, 'name' => 'CU Scanner — Safe',           'enabled' => 1 ],
            [ 'id' => 20, 'name' => 'CU Scanner — Aggressive',     'enabled' => 0 ],
        ];
        $result = $this->make_manager()->bump_scanner_groups();
        $this->assertTrue( $result );
        $names = array_column( FakeRuleRepository::$groups, 'name' );
        $this->assertContains( 'CU Scanner — Safe v2',       $names );
        $this->assertContains( 'CU Scanner — Aggressive v1', $names );
        // Pre-existing v1 group must be unchanged
        $v1_group = null;
        foreach ( FakeRuleRepository::$groups as $g ) {
            if ( $g['id'] === 5 ) { $v1_group = $g; break; }
        }
        $this->assertSame( 'CU Scanner — Safe v1', $v1_group['name'], 'Pre-existing v1 group must not be renamed' );
        $this->assertSame( 0, $v1_group['enabled'], 'Pre-existing v1 group enabled state must be unchanged' );
    }

    // --- Non-scanner groups are not touched ---

    public function test_bump_does_not_rename_groups_with_similar_but_not_exact_names(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 1, 'name' => 'CU Scanner — Safe Rules', 'enabled' => 1 ],  // not a match
            [ 'id' => 2, 'name' => 'My CU Scanner — Safe',    'enabled' => 1 ],  // not a match
            [ 'id' => 3, 'name' => 'CU Scanner — Safe v1', 'enabled' => 0 ],  // already versioned — must not be double-versioned
        ];
        $this->make_manager()->bump_scanner_groups();
        // Neither group should be renamed — exact name match only
        $names = array_column( FakeRuleRepository::$groups, 'name' );
        $this->assertNotContains( 'CU Scanner — Safe Rules v1', $names );
        $this->assertNotContains( 'My CU Scanner — Safe v1',    $names );
        $this->assertNotContains( 'CU Scanner — Safe v1 v2', $names );
    }

    // --- DB failure ---

    public function test_bump_returns_wp_error_when_update_group_fails(): void {
        $failing_repo = new class extends FakeRuleRepository {
            public static function update_group( int $id, array $data ): bool {
                return false; // simulate DB failure on every update
            }
        };
        $failing_repo::$groups = [
            [ 'id' => 10, 'name' => 'CU Scanner — Safe', 'enabled' => 1 ],
        ];
        $manager = new GroupVersionManager( $failing_repo::class );
        $result  = $manager->bump_scanner_groups();
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    // --- Rollback functionality ---

    public function test_rollback_restores_renamed_group_to_original_name_and_enables_it(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 10, 'name' => 'CU Scanner — Safe', 'enabled' => 1 ],
        ];
        $manager = $this->make_manager();
        $manager->bump_scanner_groups();

        // After bump: group should be renamed to v1 and disabled
        $g = FakeRuleRepository::$groups[0];
        $this->assertSame( 'CU Scanner — Safe v1', $g['name'] );

        // After rollback: group should be restored to original name and enabled
        $manager->rollback();
        $g = FakeRuleRepository::$groups[0];
        $this->assertSame( 'CU Scanner — Safe', $g['name'] );
        $this->assertSame( 1, $g['enabled'] );
    }

    public function test_rollback_is_safe_when_nothing_was_bumped(): void {
        FakeRuleRepository::$groups = [];
        $manager = $this->make_manager();
        $manager->rollback(); // must not throw
        $this->assertEmpty( FakeRuleRepository::$groups );
    }

    // --- Rule clearing (table-wide UNIQUE constraint) ---

    public function test_bump_clears_rules_from_old_scanner_group(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 10, 'name' => 'CU Scanner — Safe', 'enabled' => 0 ],
        ];
        FakeRuleRepository::$rules = [
            [ 'id' => 201, 'group_id' => 10, 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-css', 'asset_type' => 'css', 'device_type' => 'all', 'source_label' => 'CU Scanner' ],
            [ 'id' => 202, 'group_id' => 10, 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-js',  'asset_type' => 'js',  'device_type' => 'all', 'source_label' => 'CU Scanner' ],
        ];

        $this->make_manager()->bump_scanner_groups();

        // All rules from the old group must be deleted
        $this->assertEmpty( FakeRuleRepository::$rules, 'Rules must be cleared from the bumped group' );
    }

    public function test_bump_only_clears_rules_from_scanner_groups_not_others(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 5,  'name' => 'Manual Group',     'enabled' => 1 ],
            [ 'id' => 10, 'name' => 'CU Scanner — Safe', 'enabled' => 0 ],
        ];
        FakeRuleRepository::$rules = [
            [ 'id' => 100, 'group_id' => 5,  'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'manual', 'asset_type' => 'css', 'device_type' => 'all', 'source_label' => 'manual' ],
            [ 'id' => 201, 'group_id' => 10, 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-css', 'asset_type' => 'css', 'device_type' => 'all', 'source_label' => 'CU Scanner' ],
        ];

        $this->make_manager()->bump_scanner_groups();

        $remaining_ids = array_column( FakeRuleRepository::$rules, 'id' );
        $this->assertContains( 100, $remaining_ids, 'Manual group rule must not be deleted' );
        $this->assertNotContains( 201, $remaining_ids, 'Scanner group rule must be deleted' );
    }

    public function test_bump_clears_rules_from_versioned_groups_too(): void {
        // Safe v1 and Safe v2 exist with rules — the UNIQUE constraint is table-wide,
        // so those rules would block re-insertion into the fresh group after the bump.
        FakeRuleRepository::$groups = [
            [ 'id' => 5,  'name' => 'CU Scanner — Safe v1', 'enabled' => 0 ],
            [ 'id' => 6,  'name' => 'CU Scanner — Safe v2', 'enabled' => 0 ],
            [ 'id' => 10, 'name' => 'CU Scanner — Safe',    'enabled' => 1 ],
        ];
        FakeRuleRepository::$rules = [
            [ 'id' => 101, 'group_id' => 5,  'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-css', 'asset_type' => 'css', 'device_type' => 'all', 'source_label' => 'CU Scanner' ],
            [ 'id' => 102, 'group_id' => 6,  'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-js',  'asset_type' => 'js',  'device_type' => 'all', 'source_label' => 'CU Scanner' ],
            [ 'id' => 201, 'group_id' => 10, 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'other',  'asset_type' => 'css', 'device_type' => 'all', 'source_label' => 'CU Scanner' ],
        ];

        $this->make_manager()->bump_scanner_groups();

        // All scanner rules (base + versioned) must be cleared
        $this->assertEmpty( FakeRuleRepository::$rules, 'Rules in versioned groups must also be cleared' );
    }

    public function test_bump_does_not_clear_rules_from_non_scanner_versioned_groups(): void {
        // A versioned group with a non-scanner base name must not be touched
        FakeRuleRepository::$groups = [
            [ 'id' => 3,  'name' => 'Manual Group v1',    'enabled' => 0 ],
            [ 'id' => 10, 'name' => 'CU Scanner — Safe',  'enabled' => 1 ],
        ];
        FakeRuleRepository::$rules = [
            [ 'id' => 50,  'group_id' => 3,  'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'manual', 'asset_type' => 'css', 'device_type' => 'all', 'source_label' => 'manual' ],
            [ 'id' => 201, 'group_id' => 10, 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-css', 'asset_type' => 'css', 'device_type' => 'all', 'source_label' => 'CU Scanner' ],
        ];

        $this->make_manager()->bump_scanner_groups();

        $remaining_ids = array_column( FakeRuleRepository::$rules, 'id' );
        $this->assertContains( 50, $remaining_ids, 'Non-scanner versioned group rule must not be deleted' );
        $this->assertNotContains( 201, $remaining_ids, 'Scanner group rule must be deleted' );
    }

    public function test_rollback_re_inserts_cleared_rules(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 10, 'name' => 'CU Scanner — Safe', 'enabled' => 0 ],
        ];
        FakeRuleRepository::$rules = [
            [ 'id' => 201, 'group_id' => 10, 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-css', 'asset_type' => 'css', 'device_type' => 'all', 'source_label' => 'CU Scanner', 'label' => null, 'condition_type' => null, 'condition_value' => null, 'condition_invert' => 0 ],
        ];

        $manager = $this->make_manager();
        $manager->bump_scanner_groups();

        // Rules cleared after bump
        $this->assertEmpty( FakeRuleRepository::$rules );

        // After rollback: group name restored AND rule re-inserted
        $manager->rollback();
        $this->assertSame( 'CU Scanner — Safe', FakeRuleRepository::$groups[0]['name'] );
        $rules_in_group = array_filter( FakeRuleRepository::$rules, fn( $r ) => $r['group_id'] === 10 );
        $this->assertCount( 1, $rules_in_group, 'Cleared rule must be re-inserted on rollback' );
    }
}

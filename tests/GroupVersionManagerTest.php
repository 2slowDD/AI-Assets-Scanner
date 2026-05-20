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
            [ 'id' => 10, 'name' => 'AA Scanner — Safe', 'enabled' => 1 ],
        ];
        $result = $this->make_manager()->bump_scanner_groups();
        $this->assertTrue( $result );
        $g = FakeRuleRepository::$groups[0];
        $this->assertSame( 'AA Scanner — Safe v1', $g['name'] );
        $this->assertSame( 0, $g['enabled'] );
    }

    public function test_bump_renames_safe_group_to_v2_when_v1_already_exists(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 5,  'name' => 'AA Scanner — Safe v1', 'enabled' => 0 ],
            [ 'id' => 10, 'name' => 'AA Scanner — Safe',    'enabled' => 1 ],
        ];
        $result = $this->make_manager()->bump_scanner_groups();
        $this->assertTrue( $result );
        $base = null;
        foreach ( FakeRuleRepository::$groups as $g ) {
            if ( $g['id'] === 10 ) { $base = $g; break; }
        }
        $this->assertSame( 'AA Scanner — Safe v2', $base['name'] );
        $this->assertSame( 0, $base['enabled'] );
    }

    public function test_bump_uses_highest_existing_version_not_count(): void {
        // v1 and v3 exist (v2 is missing) — next must be v4
        FakeRuleRepository::$groups = [
            [ 'id' => 3,  'name' => 'AA Scanner — Safe v1', 'enabled' => 0 ],
            [ 'id' => 4,  'name' => 'AA Scanner — Safe v3', 'enabled' => 0 ],
            [ 'id' => 10, 'name' => 'AA Scanner — Safe',    'enabled' => 1 ],
        ];
        $this->make_manager()->bump_scanner_groups();
        $base = null;
        foreach ( FakeRuleRepository::$groups as $g ) {
            if ( $g['id'] === 10 ) { $base = $g; break; }
        }
        $this->assertSame( 'AA Scanner — Safe v4', $base['name'] );
    }

    // --- Aggressive group versioning ---

    public function test_bump_renames_aggressive_group_to_v1(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 20, 'name' => 'AA Scanner — Aggressive', 'enabled' => 1 ],
        ];
        $this->make_manager()->bump_scanner_groups();
        $this->assertSame( 'AA Scanner — Aggressive v1', FakeRuleRepository::$groups[0]['name'] );
        $this->assertSame( 0, FakeRuleRepository::$groups[0]['enabled'] );
    }

    // --- Both groups together ---

    public function test_bump_renames_both_safe_and_aggressive_independently(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 5,  'name' => 'AA Scanner — Safe v1',       'enabled' => 0 ],
            [ 'id' => 10, 'name' => 'AA Scanner — Safe',           'enabled' => 1 ],
            [ 'id' => 20, 'name' => 'AA Scanner — Aggressive',     'enabled' => 0 ],
        ];
        $result = $this->make_manager()->bump_scanner_groups();
        $this->assertTrue( $result );
        $names = array_column( FakeRuleRepository::$groups, 'name' );
        $this->assertContains( 'AA Scanner — Safe v2',       $names );
        $this->assertContains( 'AA Scanner — Aggressive v1', $names );
        // Pre-existing v1 group must be unchanged
        $v1_group = null;
        foreach ( FakeRuleRepository::$groups as $g ) {
            if ( $g['id'] === 5 ) { $v1_group = $g; break; }
        }
        $this->assertSame( 'AA Scanner — Safe v1', $v1_group['name'], 'Pre-existing v1 group must not be renamed' );
        $this->assertSame( 0, $v1_group['enabled'], 'Pre-existing v1 group enabled state must be unchanged' );
    }

    // --- Non-scanner groups are not touched ---

    public function test_bump_does_not_rename_groups_with_similar_but_not_exact_names(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 1, 'name' => 'AA Scanner — Safe Rules', 'enabled' => 1 ],  // not a match
            [ 'id' => 2, 'name' => 'My AA Scanner — Safe',    'enabled' => 1 ],  // not a match
            [ 'id' => 3, 'name' => 'AA Scanner — Safe v1', 'enabled' => 0 ],  // already versioned — must not be double-versioned
        ];
        $this->make_manager()->bump_scanner_groups();
        // Neither group should be renamed — exact name match only
        $names = array_column( FakeRuleRepository::$groups, 'name' );
        $this->assertNotContains( 'AA Scanner — Safe Rules v1', $names );
        $this->assertNotContains( 'My AA Scanner — Safe v1',    $names );
        $this->assertNotContains( 'AA Scanner — Safe v1 v2', $names );
    }

    // --- DB failure ---

    public function test_bump_returns_wp_error_when_update_group_fails(): void {
        $failing_repo = new class extends FakeRuleRepository {
            public static function update_group( int $id, array $data ): bool {
                return false; // simulate DB failure on every update
            }
        };
        $failing_repo::$groups = [
            [ 'id' => 10, 'name' => 'AA Scanner — Safe', 'enabled' => 1 ],
        ];
        $manager = new GroupVersionManager( $failing_repo::class );
        $result  = $manager->bump_scanner_groups();
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    // --- Rollback functionality ---

    public function test_rollback_restores_renamed_group_to_original_name_and_enables_it(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 10, 'name' => 'AA Scanner — Safe', 'enabled' => 1 ],
        ];
        $manager = $this->make_manager();
        $manager->bump_scanner_groups();

        // After bump: group should be renamed to v1 and disabled
        $g = FakeRuleRepository::$groups[0];
        $this->assertSame( 'AA Scanner — Safe v1', $g['name'] );

        // After rollback: group should be restored to original name and enabled
        $manager->rollback();
        $g = FakeRuleRepository::$groups[0];
        $this->assertSame( 'AA Scanner — Safe', $g['name'] );
        $this->assertSame( 1, $g['enabled'] );
    }

    public function test_rollback_is_safe_when_nothing_was_bumped(): void {
        FakeRuleRepository::$groups = [];
        $manager = $this->make_manager();
        $manager->rollback(); // must not throw
        $this->assertEmpty( FakeRuleRepository::$groups );
    }

    // --- Rule retention (group_id is now part of UNIQUE key) ---

    public function test_bump_retains_rules_in_renamed_group(): void {
        FakeRuleRepository::$groups = [
            [ 'id' => 10, 'name' => 'AA Scanner — Safe', 'enabled' => 1 ],
        ];
        FakeRuleRepository::$rules = [
            [ 'id' => 201, 'group_id' => 10, 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-css', 'asset_type' => 'css', 'device_type' => 'all', 'source_label' => 'AA Scanner' ],
            [ 'id' => 202, 'group_id' => 10, 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'my-js',  'asset_type' => 'js',  'device_type' => 'all', 'source_label' => 'AA Scanner' ],
        ];

        $this->make_manager()->bump_scanner_groups();

        // Group must be renamed and disabled
        $g = FakeRuleRepository::$groups[0];
        $this->assertSame( 'AA Scanner — Safe v1', $g['name'] );
        $this->assertSame( 0, $g['enabled'] );

        // Rules must still be in the group — NOT deleted
        $remaining_ids = array_column( FakeRuleRepository::$rules, 'id' );
        $this->assertContains( 201, $remaining_ids, 'Rule 201 must be retained in renamed group' );
        $this->assertContains( 202, $remaining_ids, 'Rule 202 must be retained in renamed group' );
    }

    public function test_bump_retains_rules_in_versioned_groups(): void {
        // v1 and v2 already have rules; base group also has rules.
        // After bump all three must retain their rules.
        FakeRuleRepository::$groups = [
            [ 'id' => 5,  'name' => 'AA Scanner — Safe v1', 'enabled' => 0 ],
            [ 'id' => 6,  'name' => 'AA Scanner — Safe v2', 'enabled' => 0 ],
            [ 'id' => 10, 'name' => 'AA Scanner — Safe',    'enabled' => 1 ],
        ];
        FakeRuleRepository::$rules = [
            [ 'id' => 101, 'group_id' => 5,  'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'css-v1', 'asset_type' => 'css', 'device_type' => 'all', 'source_label' => 'AA Scanner' ],
            [ 'id' => 102, 'group_id' => 6,  'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'css-v2', 'asset_type' => 'css', 'device_type' => 'all', 'source_label' => 'AA Scanner' ],
            [ 'id' => 201, 'group_id' => 10, 'url_pattern' => 'https://example.com/', 'match_type' => 'exact', 'asset_handle' => 'css-base', 'asset_type' => 'css', 'device_type' => 'all', 'source_label' => 'AA Scanner' ],
        ];

        $this->make_manager()->bump_scanner_groups();

        // All rules must still exist
        $remaining_ids = array_column( FakeRuleRepository::$rules, 'id' );
        $this->assertContains( 101, $remaining_ids, 'v1 rule must be retained' );
        $this->assertContains( 102, $remaining_ids, 'v2 rule must be retained' );
        $this->assertContains( 201, $remaining_ids, 'base group rule must be retained' );
    }

}

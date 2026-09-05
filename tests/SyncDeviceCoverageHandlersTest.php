<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use CUScanner\Scanner\CuJsonBuilder;
use WP_Mock;
use WP_Mock\Tools\TestCase;

require_once __DIR__ . '/SnapshotManagerTest.php';        // FakeRuleRepository (AC-10 mirrors)
require_once __DIR__ . '/SyncScopeBuildResultTest.php';   // SyncScopeBuildResultTestFixtureAccess (Fixture E)
if ( ! class_exists( 'CodeUnloader\\Core\\RuleRepository', false ) ) {
    class_alias( FakeRuleRepository::class, 'CodeUnloader\\Core\\RuleRepository' );
}

/**
 * FU-AAS-SYNC-DEVICE-DUPLICATES — handler side, through the REAL sync_to_cu / undo_last_push_sync
 * with RulePusher's default repo aliased to FakeRuleRepository (same isolation as
 * SyncScopeHandlersTest: the alias collides in-process with ResultTruthRefundClaimTest's FakeCuRepo).
 *   AC-2 Seed D (the operator's table mirrored on site.test) ⇒ 0 / 1 / 5; Seed D′ ⇒ 0 / 0 / 6.
 *   AC-7 produced Fixture E ⇒ card = line partition identity.
 *   AC-9 undo exactness after Seed D.
 * 🔴 Scanner-group names come from CuJsonBuilder (em dash) — never retyped (spec §5, r2 Major).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SyncDeviceCoverageHandlersTest extends TestCase {
    private array $options = [];
    private $captured = null;
    private $error = null;

    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); FakeRuleRepository::reset(); $this->options = []; $this->captured = null; $this->error = null; }
    public function tearDown(): void { unset( $_POST['job_id'], $_POST['confirmed'] ); WP_Mock::tearDown(); parent::tearDown(); }

    private function wp_parse_url_live(): void {
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
    }

    /** The production group defs (id 1 Safe, id 2 Aggressive) — from the builder, em dash asserted. */
    private function production_groups(): array {
        $groups = ( new CuJsonBuilder() )->build( [ [ 'url' => 'https://site.test/', 'status' => 'done', 'assets' => [] ] ], [] )['groups'];
        $this->assertStringContainsString( "\u{2014}", $groups[0]['name'] );
        $this->assertStringContainsString( "\u{2014}", $groups[1]['name'] );
        return $groups;
    }

    private function rule( string $handle, string $device, int $group = 2 ): array {
        return [ 'url_pattern' => 'https://site.test/', 'match_type' => 'exact', 'asset_handle' => $handle, 'asset_type' => 'css', 'device_type' => $device, 'group_id' => $group, 'source_label' => 'AA Scanner' ];
    }

    /** Scan JSON D: the six rules the ET continuation sent — one All + five Desktop legs. */
    private function scan_json_d( array $groups ): array {
        return [
            'version' => 2, 'exported_at' => '2026-09-05T00:00:00+00:00', 'groups' => $groups,
            'rules' => [
                $this->rule( 'woo-layout', 'all' ),
                $this->rule( 'liquid-glass', 'desktop' ), $this->rule( 'enlighterjs', 'desktop' ), $this->rule( 'gwm', 'desktop' ),
                $this->rule( 'wc-address', 'desktop' ),   $this->rule( 'eb-reusable', 'desktop' ),
            ],
            'by_page' => [ [ 'safe' => 0, 'aggressive' => 6, 'needed' => 0 ] ],
            'scanned_patterns' => [ 'https://site.test/' ],
        ];
    }

    /** Seed D: both scanner groups pre-created (Safe empty); 10 rows in Aggressive. Returns [safe_gid, agg_gid]. */
    private function seed_d( array $groups ): array {
        $safe = FakeRuleRepository::create_group( $groups[0]['name'], 'safe' );
        $agg  = FakeRuleRepository::create_group( $groups[1]['name'], 'agg' );
        foreach ( [ [ 'liquid-glass', 'all' ], [ 'liquid-glass', 'mobile' ], [ 'enlighterjs', 'all' ], [ 'gwm', 'mobile' ], [ 'wc-address', 'all' ],
                   [ 'eb-reusable', 'all' ], [ 'eb-reusable', 'desktop' ], [ 'eb-reusable', 'mobile' ], [ 'woo-layout', 'all' ], [ 'ppcp', 'all' ] ] as [ $h, $d ] ) {
            FakeRuleRepository::create_rule( $this->rule( $h, $d, $agg ) );
        }
        $this->assertCount( 10, FakeRuleRepository::$rules, 'Seed D is 10 rows' );
        return [ $safe, $agg ];
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
        $this->wp_parse_url_live();
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default );
        WP_Mock::userFunction( 'update_option' )->andReturnUsing( function ( $name, $value ) { $this->options[ $name ] = $value; return true; } );
        WP_Mock::userFunction( 'delete_option' )->andReturnUsing( function ( $name ) { unset( $this->options[ $name ] ); return true; } );
        WP_Mock::userFunction( 'wp_send_json_success' )->andReturnUsing( function ( $data ) { $this->captured = $data; } );
        WP_Mock::userFunction( 'wp_send_json_error' )->andReturnUsing( function ( $data ) { $this->error = $data; } );
    }

    private function counts(): array { return [ $this->captured['appended_safe'], $this->captured['appended_aggressive'], $this->captured['already_present'] ]; }

    // -------------------------------------------------------------- AC-2
    public function test_ac2_seed_d_reports_0_1_5_and_inserts_only_the_gwm_desktop_leg(): void {
        $this->wp_parse_url_live();
        $groups = $this->production_groups();
        $this->stub( $this->scan_json_d( $groups ) );
        [ , $agg ] = $this->seed_d( $groups );

        ( new ScannerAjax() )->sync_to_cu();

        $this->assertNull( $this->error );
        $this->assertSame( [ 0, 1, 5 ], $this->counts(), 'gwm was Mobile-only: its Desktop leg is genuinely new' );
        $this->assertSame( [], $this->captured['created_group_ids'], 'both groups pre-existed under the PRODUCTION names' );
        $this->assertCount( 1, $this->captured['created_rule_ids'] );
        $this->assertCount( 11, FakeRuleRepository::$rules );
        $new = end( FakeRuleRepository::$rules );
        $this->assertSame( [ 'gwm', 'desktop', $agg ], [ $new['asset_handle'], $new['device_type'], $new['group_id'] ] );
        $this->assertTrue( $this->captured['undo_state']['available'] );
    }

    public function test_ac2_seed_d_prime_reports_0_0_6_and_inserts_nothing(): void {
        $this->wp_parse_url_live();
        $groups = $this->production_groups();
        $this->stub( $this->scan_json_d( $groups ) );
        [ , $agg ] = $this->seed_d( $groups );
        FakeRuleRepository::create_rule( $this->rule( 'gwm', 'all', $agg ) );   // the counterfactual All row

        ( new ScannerAjax() )->sync_to_cu();

        $this->assertNull( $this->error );
        $this->assertSame( [ 0, 0, 6 ], $this->counts() );
        $this->assertSame( [], $this->captured['created_rule_ids'] );
        $this->assertCount( 11, FakeRuleRepository::$rules, 'unchanged' );
        $this->assertFalse( $this->captured['undo_state']['available'], 'nothing to undo after an all-present Sync' );
    }

    // -------------------------------------------------------------- AC-7
    public function test_ac7_produced_fixture_e_card_equals_line(): void {
        $run = ( new SyncScopeBuildResultTestFixtureAccess() )->fixture_e_plain_run( $this );
        $this->assertSame( [ 1, 5 ], [ $run['payload']['apply_safe_count'], $run['payload']['apply_aggressive_count'] ], 'the card' );

        $this->stub( $run['json'], 'job-e' );
        $groups = $run['json']['groups'];
        $safe = FakeRuleRepository::create_group( $groups[0]['name'], 'safe' );
        $agg  = FakeRuleRepository::create_group( $groups[1]['name'], 'agg' );
        // Seed E: d1 covered by All; a1 covered by a Desktop+Mobile pair; m1 exact; a2 has a lone Desktop row (NOT covered).
        foreach ( [ [ 'd1', 'all' ], [ 'a1', 'desktop' ], [ 'a1', 'mobile' ], [ 'm1', 'mobile' ], [ 'a2', 'desktop' ] ] as [ $h, $d ] ) {
            FakeRuleRepository::create_rule( $this->rule( $h, $d, $agg ) );
        }

        ( new ScannerAjax() )->sync_to_cu();

        $this->assertNull( $this->error );
        $this->assertSame( [ 1, 2, 3 ], $this->counts(), 's1 new (safe); d2 + a2 new (aggressive); d1, a1, m1 present' );
        $this->assertSame( 1 + 5, array_sum( $this->counts() ), 'partition identity: apply_safe + apply_aggressive = appended + present' );
        $this->assertCount( 3, $this->captured['created_rule_ids'] );
    }

    // -------------------------------------------------------------- AC-9
    public function test_ac9_undo_after_seed_d_deletes_exactly_the_one_inserted_row(): void {
        $this->wp_parse_url_live();
        $groups = $this->production_groups();
        $this->stub( $this->scan_json_d( $groups ) );
        $this->seed_d( $groups );
        ( new ScannerAjax() )->sync_to_cu();
        $inserted = $this->captured['created_rule_ids'];
        $this->assertCount( 1, $inserted );

        $manifest = $this->options['aias_last_push_sync_undo'] ?? null;
        $this->assertSame( $inserted, $manifest['rule_ids'] ?? null, 'the manifest holds exactly the inserted id' );

        $this->captured = null;
        ( new ScannerAjax() )->undo_last_push_sync();

        $this->assertNull( $this->error );
        $this->assertSame( 1, $this->captured['deleted_rule_count'] );
        $this->assertSame( 0, $this->captured['disabled_group_count'], 'both groups pre-existed' );
        $this->assertCount( 10, FakeRuleRepository::$rules, 'the 10 seeded rows survive' );
        $this->assertSame( $inserted, FakeRuleRepository::$deleted_rule_ids );
    }
}

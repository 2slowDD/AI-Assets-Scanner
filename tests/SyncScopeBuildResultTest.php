<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use CUScanner\Scanner\CuJsonBuilder;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Task 2 review fold (Controller Ruling, 2026-09-05): shared fixture logic for
 * SyncScopeBuildResultTest (the AC-1/5/10/11 producer suite) and
 * SyncScopeBuildResultTestFixtureAccess (the AC-9 helper below) — ONE implementation of
 * page(), r_orig_from(), the WP_Mock stub set, and the AC-1 ET-rescan scenario, so editing
 * AC-1's fixture cannot silently desync from what AC-9 replays. A trait is not a TestCase
 * subclass, so PHPUnit's directory suite loader does not collect it as its own test case —
 * the Risky count stays at 2 (verified: see task-2-report.md fix-round evidence).
 */
trait SyncScopeBuildResultFixtures {
    /** A worker page row: assets are (handle,type,desktop,mobile); loaded=false + coverage 0 on both
     * devices has no 'bucket' field, so classify() falls through to the legacy {loaded,coverage}
     * derivation (class-cu-json-builder.php classify() ~L213): !loaded => 'absent' on both devices,
     * which combine()'s 'absent,absent' cell maps to a SAFE rule (group 1) — corrected 2026-09-05,
     * task-4 review (was inverted: "aggressive by default"). */
    private function page( string $url, array $handles, array $extra = [] ): array {
        $assets = [];
        foreach ( $handles as $h ) {
            $assets[] = [
                'handle'  => $h,
                'type'    => 'style',
                'desktop' => [ 'loaded' => false, 'coverage' => 0.0 ],
                'mobile'  => [ 'loaded' => false, 'coverage' => 0.0 ],
            ];
        }
        return array_merge( [ 'url' => $url, 'status' => 'done', 'assets' => $assets ], $extra );
    }

    /**
     * A worker page row driven by the AUTHORITATIVE per-device `bucket` field
     * (class-cu-json-builder.php classify() ~L203), so the caller can request SAFE and
     * AGGRESSIVE rules on the SAME page without relying on the legacy fallback (which only
     * ever emits SAFE). $handles is [handle => bucket], bucket one of 'absent'/'aggressive'/
     * 'needed', applied identically to BOTH devices (dual-device confirmation — combine()'s
     * 'absent,absent' => Safe, 'aggressive,aggressive' => Aggressive, 'needed,needed' => no rule).
     */
    private function page_with_buckets( string $url, array $handles, array $extra = [] ): array {
        $assets = [];
        foreach ( $handles as $h => $bucket ) {
            $assets[] = [
                'handle'  => $h,
                'type'    => 'style',
                'desktop' => [ 'loaded' => false, 'coverage' => 0.0, 'bucket' => $bucket ],
                'mobile'  => [ 'loaded' => false, 'coverage' => 0.0, 'bucket' => $bucket ],
            ];
        }
        return array_merge( [ 'url' => $url, 'status' => 'done', 'assets' => $assets ], $extra );
    }

    /** The R_orig transient EXACTLY as persist_r_orig() writes it, from a REAL CuJsonBuilder::build of the parent pages. */
    private function r_orig_from( array $parent_pages ): array {
        $built = ( new CuJsonBuilder() )->build( $parent_pages, [] );
        $keys  = [];
        foreach ( $built['rules'] as $r ) {
            $keys[] = [
                'url_pattern'  => $r['url_pattern'],
                'asset_handle' => $r['asset_handle'],
                'asset_type'   => $r['asset_type'],
                'device_type'  => $r['device_type'],
                'group_id'     => $r['group_id'],
            ];
        }
        return [ 'urls' => array_values( array_unique( array_column( $parent_pages, 'url' ) ) ), 'rules' => $keys ];
    }

    /**
     * Registers the full WP_Mock stub set do_build_result() needs, backed by the given
     * $options/$transients arrays BY REFERENCE — so the TestCase (its own $this->options /
     * $this->transients properties) and the AC-9/AC-2(c) helpers (local arrays) share this ONE
     * implementation instead of two hand-copied stub sets drifting apart.
     *
     * wp_parse_url is NOT registered here (task-4 review fold, 2026-09-05: removed a redundant
     * duplicate registration — every call site below already registers it live BEFORE calling
     * this method, either in setUp() (SyncScopeBuildResultTest) or inline (the fixture-access
     * helpers), because scenario building runs r_orig_from() BEFORE this method, which itself
     * runs the REAL CuJsonBuilder::build() (-> UrlPattern::from_url) ahead of this stub set).
     * Every caller of stub_everything_impl() MUST register wp_parse_url live first.
     */
    private function stub_everything_impl( array $pages, array &$options, array &$transients ): void {
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://site.test' );
        WP_Mock::userFunction( '__' )->andReturnUsing( fn( $t, $d = null ) => $t );
        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [
            'status' => 'complete', 'total' => count( $pages ), 'completed' => count( $pages ), 'pages' => $pages, 'flags' => [],
        ] ) );
        WP_Mock::userFunction( 'get_option' )->andReturnUsing( function ( $k, $default = false ) use ( &$options ) {
            if ( 'cu_scanner_railway_url' === $k ) { return 'https://cu-scanner-railway-production.up.railway.app'; }
            if ( 'cu_scanner_api_key' === $k )     { return 'api-key-123'; }
            return array_key_exists( $k, $options ) ? $options[ $k ] : $default;   // ratchet default-ON falls through to $default (true)
        } );
        WP_Mock::userFunction( 'update_option' )->andReturnUsing( function ( $k, $v ) use ( &$options ) { $options[ $k ] = $v; return true; } );
        WP_Mock::userFunction( 'get_transient' )->andReturnUsing( fn( $k ) => $transients[ $k ] ?? false );
        WP_Mock::userFunction( 'set_transient' )->andReturnUsing( function ( $k, $v ) use ( &$transients ) { $transients[ $k ] = $v; return true; } );
        WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
        WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $d, $f = 0 ) => json_encode( $d, $f ) );
        WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value = null ) => $value );
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        WP_Mock::userFunction( 'do_action' )->andReturn( null );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );   // CU absent => can_push false; apply_* do not depend on CU
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => (string) $v );
        WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
        WP_Mock::userFunction( 'absint' )->andReturnUsing( fn( $v ) => abs( (int) $v ) );
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( true );
        WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
    }

    /**
     * The AC-1 ET-rescan scenario (spec §5 / task-2 brief): parent scan = home + other page;
     * rescan = the home page only, Extra-Time charged. Returns the R_orig transient VALUE
     * (not yet stored — the caller decides the transient key) and the rescan pages[] the
     * worker returns. Shared by SyncScopeBuildResultTest::test_ac1_et_rescan_...() and the
     * AC-9 helper (SyncScopeBuildResultTestFixtureAccess::et_rescan_json()) so the two can
     * never silently diverge on what "the AC-1 scenario" means.
     *
     * Caller MUST have wp_parse_url live BEFORE calling: r_orig_from() runs the REAL
     * CuJsonBuilder::build() -> UrlPattern::from_url ahead of stub_everything_impl().
     */
    private function et_rescan_scenario(): array {
        $parent = [ $this->page( 'https://site.test/', [ 'home-a' ] ), $this->page( 'https://site.test/other/', [ 'other-a', 'other-b' ] ) ];
        return [
            'r_orig' => $this->r_orig_from( $parent ),
            'rescan' => [ $this->page( 'https://site.test/', [ 'home-a' ], [ 'extra_time_charged' => true ] ) ],
        ];
    }

    /**
     * Final-review fold (2026-09-05): the AC-5 mixed-host ET rescan scenario (spec §5 AC-5),
     * extracted out of SyncScopeBuildResultTest::test_ac5_mixed_host_et_rescan_with_no_internal_in_scope_rules()
     * the same way et_rescan_scenario() was extracted for AC-1/AC-9, so that test and
     * SyncScopeBuildResultTestFixtureAccess::mixed_host_et_json() (the AC-5 handler-leg helper)
     * can never silently diverge on what "the AC-5 scenario" means.
     *
     * Parent: internal /other/ produced rules; internal /empty/ has no rule (asset in use).
     * Rescan: /empty/ (still no rule — asset now in use) + an external /x/ page that DOES
     * produce rules. Net effect: the rescan itself contributes no host-internal rule, while the
     * ratchet carries the internal /other/ rule forward into the stored JSON's 'rules' — a
     * host-internal rule that sits OUTSIDE scanned_patterns (which is just /empty/ + /x/).
     *
     * Caller MUST have wp_parse_url live BEFORE calling: r_orig_from() runs the REAL
     * CuJsonBuilder::build() -> UrlPattern::from_url ahead of stub_everything_impl().
     */
    private function mixed_host_et_scenario(): array {
        $parent = [ $this->page( 'https://site.test/other/', [ 'other-a' ] ), $this->page( 'https://site.test/empty/', [] ), $this->page( 'https://ext.test/x/', [ 'x-a' ] ) ];
        $used   = [ 'handle' => 'used-a', 'type' => 'style', 'desktop' => [ 'loaded' => true, 'coverage' => 0.9 ], 'mobile' => [ 'loaded' => true, 'coverage' => 0.9 ] ];
        $rescan = [
            [ 'url' => 'https://site.test/empty/', 'status' => 'done', 'assets' => [ $used ], 'extra_time_charged' => true ],
            $this->page( 'https://ext.test/x/', [ 'x-a' ], [ 'extra_time_charged' => true ] ),
        ];
        return [
            'r_orig' => $this->r_orig_from( $parent ),
            'rescan' => $rescan,
        ];
    }
}

/**
 * FU-AAS-SYNC-SCOPE-LAST-SCAN — producer side, through the REAL do_build_result():
 *   AC-1  scanned_patterns is stored; the ratchet-carried other-page rule STAYS in rules.
 *   AC-5  mixed-host ET rescan with no internal in-scope rules: has_internal_rules false,
 *         apply_* 0/0, scan totals > 0 — on BOTH payload writers.
 *   AC-10 Fixture B (plain): apply_* = scoped host-internal counts on BOTH writers; scan totals
 *         unchanged; (v) has_internal_rules === (apply_safe + apply_agg) > 0 with NON-zero counts;
 *         get_badge_state returns the persisted option verbatim (the W3 hop).
 *   AC-11 the stored JSON (what download_json echoes verbatim) still satisfies CU's importer predicate.
 * P17: only the Railway HTTP boundary is stubbed. No hand-composed merged rule list.
 */
class SyncScopeBuildResultTest extends TestCase {
    use SyncScopeBuildResultFixtures;

    /** @var array<string,mixed> */
    private array $options = [];
    /** @var array<string,mixed> */
    private array $transients = [];

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->options    = [];
        $this->transients = [];
        // wp_parse_url must be live BEFORE stub_everything() runs: AC-1's ET-rescan fixture calls
        // r_orig_from(), which runs the REAL CuJsonBuilder::build() (-> UrlPattern::from_url) ahead
        // of stub_everything(). Test-stub-only fix (brief Step 8 pattern); no producer line moved.
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( (string) $u, $c ) );
    }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    private function stub_everything( array $pages ): void {
        $this->stub_everything_impl( $pages, $this->options, $this->transients );
    }

    private function stored_json( string $job ): array {
        $this->assertArrayHasKey( 'cu_scanner_json_' . $job, $this->options, 'do_build_result stored the scan JSON' );
        return json_decode( (string) $this->options[ 'cu_scanner_json_' . $job ], true );
    }

    // ---------------------------------------------------------------- AC-1
    public function test_ac1_et_rescan_stores_scanned_patterns_and_keeps_the_carried_rule(): void {
        $scenario = $this->et_rescan_scenario();
        $this->transients['cu_scanner_r_orig_1'] = $scenario['r_orig'];
        $this->stub_everything( $scenario['rescan'] );

        ( new ScannerAjax() )->do_build_result( 'job-et', 'tok' );

        $json = $this->stored_json( 'job-et' );
        $this->assertSame( [ 'https://site.test/' ], $json['scanned_patterns'] );
        $patterns = array_column( $json['rules'], 'url_pattern' );
        $this->assertContains( 'https://site.test/other', $patterns, 'the ratchet-carried other-page rules STAY in the stored merged set (spec §3.1: stored JSON untouched)' );
        $this->assertContains( 'https://site.test/', $patterns );
    }

    public function test_ac1_negative_plain_scan_stores_its_pages_and_every_rule_is_in_scope(): void {
        $pages = [ $this->page( 'https://site.test/', [ 'home-a' ] ), $this->page( 'https://site.test/b/', [ 'b-a' ] ) ];
        $this->stub_everything( $pages );
        ( new ScannerAjax() )->do_build_result( 'job-plain', 'tok' );
        $json = $this->stored_json( 'job-plain' );
        $this->assertSame( [ 'https://site.test/', 'https://site.test/b' ], $json['scanned_patterns'] );
        $this->assertNotEmpty( $json['rules'], 'the in-scope loop below cannot be vacuous' );
        foreach ( $json['rules'] as $r ) { $this->assertContains( $r['url_pattern'], $json['scanned_patterns'] ); }
    }

    // ---------------------------------------------------------------- AC-10 (Fixture B, plain) + (v) + W3 hop
    public function test_ac10_apply_counts_are_host_internal_and_scoped_on_both_writers(): void {
        // Fixture B: p1 S1+A2 needs a SAFE rule — CuJsonBuilder emits group 1 for a page-level "absent on both devices"
        // asset only under its own rules; to keep the fixture honest we take whatever the REAL builder emits and
        // assert against ITS counts rather than hard-coding S 1 (spec §5 declares the shape; the builder decides groups).
        $pages = [
            $this->page( 'https://site.test/p1/', [ 'p1-a', 'p1-b', 'p1-c' ] ),
            $this->page( 'https://site.test/p2/', [ 'p2-a', 'p2-b', 'p2-c' ] ),
            $this->page( 'https://site.test/p3/', [ 'p3-a' ] ),
            $this->page( 'https://ext.test/x/',   [ 'x-a', 'x-b' ] ),
        ];
        $this->stub_everything( $pages );
        // Ruling D — seed a matching history record so do_build_result's internal update_status()
        // call (class-scanner-ajax.php ~L1401) transitions THIS job to 'complete'. This lets the
        // REAL, uninjected get_badge_state() (below) resolve to 'green' purely from
        // get_option()-backed state, with no MenuBadge/Code Unloader injection needed.
        $this->options['cu_scanner_history'] = [ [ 'job_id' => 'job-b', 'status' => 'queued' ] ];
        $payload = ( new ScannerAjax() )->do_build_result( 'job-b', 'tok' );

        $json       = $this->stored_json( 'job-b' );
        $internal   = array_values( array_filter( $json['rules'], fn( $r ) => str_starts_with( $r['url_pattern'], 'https://site.test/' ) ) );
        $expected   = ScannerAjax::rule_counts_from_rules( $internal );
        $totals     = ScannerAjax::rule_counts_from_rules( $json['rules'] );
        $this->assertGreaterThan( $expected['safe'] + $expected['aggressive'], $totals['safe'] + $totals['aggressive'], 'the external page contributed rules to the SCAN totals' );

        foreach ( [ 'live payload' => $payload, 'aias_last_result option' => $this->options['aias_last_result'] ] as $where => $p ) {
            $this->assertSame( $expected['safe'],       $p['apply_safe_count'],       "$where: apply_safe_count is host-internal + scoped" );
            $this->assertSame( $expected['aggressive'], $p['apply_aggressive_count'], "$where: apply_aggressive_count is host-internal + scoped" );
            $this->assertSame( ( $p['apply_safe_count'] + $p['apply_aggressive_count'] ) > 0, $p['has_internal_rules'], "$where: AC-10(v) totality — flag === counts > 0 with NON-zero counts" );
            $this->assertGreaterThan( 0, $p['apply_safe_count'] + $p['apply_aggressive_count'], "$where: AC-10(v) is only meaningful on non-zero counts" );
        }
        // scan totals stay the by_page sums (external included): the option carries agg_count, the live payload aggressive_count
        $this->assertSame( $totals['aggressive'], $payload['aggressive_count'] );
        $this->assertSame( $totals['aggressive'], $this->options['aias_last_result']['agg_count'] );
        // Task-4 review fold (2026-09-05): the aggressive leg above is 0 === 0 on this all-SAFE
        // fixture (page() emits only Safe rules — the legacy classify() fallback never yields
        // Aggressive), so it passes even if the safe/aggressive split were silently swapped. Add
        // the safe_count leg, which IS non-zero on this fixture, so "scan totals unchanged" is
        // actually exercised.
        $this->assertGreaterThan( 0, $totals['safe'], 'this fixture must actually produce safe rules for the leg below to be meaningful' );
        $this->assertSame( $totals['safe'], $payload['safe_count'] );
        $this->assertSame( $totals['safe'], $this->options['aias_last_result']['safe_count'] );

        // W3 hop: get_badge_state returns the persisted option verbatim when green.
        // The MenuBadge mock this brief originally sketched is unreachable — get_badge_state()
        // constructs `new \CUScanner\MenuBadge()` itself with no injection seam — so the real path
        // is driven end-to-end instead: the history seed above lets the real, uninjected MenuBadge
        // resolve 'green' from get_option()-backed state, and wp_send_json_success's payload is
        // captured to assert `result` is exactly the persisted option (apply_* included).
        $captured = null;
        WP_Mock::userFunction( 'wp_send_json_success' )->once()->andReturnUsing( function ( $data ) use ( &$captured ) { $captured = $data; } );
        ( new ScannerAjax() )->get_badge_state();
        $this->assertSame( 'green', $captured['badge'], 'W3 hop: the real get_badge_state() resolves green from option state alone' );
        $this->assertSame( $this->options['aias_last_result'], $captured['result'], 'W3 hop: result is the persisted option VERBATIM (apply_* included)' );
        $this->assertSame( $this->options['aias_last_result']['apply_safe_count'], $expected['safe'], 'the option get_badge_state returns verbatim carries apply_safe_count' );
        $this->assertSame( $this->options['aias_last_result']['apply_aggressive_count'], $expected['aggressive'] );
    }

    // ---------------------------------------------------------------- AC-5 (PHP side)
    public function test_ac5_mixed_host_et_rescan_with_no_internal_in_scope_rules(): void {
        // Scenario extracted to the shared trait (final-review fold, 2026-09-05) so the AC-5
        // handler-leg helper (SyncScopeBuildResultTestFixtureAccess::mixed_host_et_json()) runs
        // the exact same fixture and can never silently diverge from this test.
        $scenario = $this->mixed_host_et_scenario();
        $this->transients['cu_scanner_r_orig_1'] = $scenario['r_orig'];
        $this->stub_everything( $scenario['rescan'] );
        $payload = ( new ScannerAjax() )->do_build_result( 'job-mixed', 'tok' );

        foreach ( [ 'live payload' => $payload, 'aias_last_result option' => $this->options['aias_last_result'] ] as $where => $p ) {
            $this->assertFalse( $p['has_internal_rules'], "$where: no internal in-scope rules" );
            $this->assertSame( 0, $p['apply_safe_count'], $where );
            $this->assertSame( 0, $p['apply_aggressive_count'], $where );
        }
        $this->assertGreaterThan( 0, $payload['safe_count'] + $payload['aggressive_count'], 'the scan totals still count the external page (tiles/History unchanged)' );
        $json = $this->stored_json( 'job-mixed' );
        $this->assertContains( 'https://site.test/other', array_column( $json['rules'], 'url_pattern' ), 'the carried internal rule is in the stored set (it is what today\'s Sync would have sent)' );
    }

    // ---------------------------------------------------------------- AC-11
    public function test_ac11_stored_json_still_satisfies_the_cu_importer_predicate(): void {
        $this->stub_everything( [ $this->page( 'https://site.test/', [ 'home-a' ] ) ] );
        ( new ScannerAjax() )->do_build_result( 'job-x', 'tok' );
        $data = $this->stored_json( 'job-x' );   // download_json() echoes this string verbatim (handler untouched)
        $this->assertArrayHasKey( 'scanned_patterns', $data );
        // Mirror of Code Unloader AdminScreen.php (v1.4.11 / v1.5.0): the ONLY validation the importer applies.
        $importable = is_array( $data ) && ( ! empty( $data['rules'] ) || ! empty( $data['groups'] ) );
        $this->assertTrue( $importable );
        $this->assertNotEmpty( $data['groups'] );
        $this->assertNotEmpty( $data['rules'] );
    }
}

/**
 * Task 2 Ruling A (2026-09-05) — AC-9 fixture-access helper. Runs the SAME AC-1 ET-rescan
 * scenario as SyncScopeBuildResultTest::test_ac1_et_rescan_stores_scanned_patterns_and_keeps_the_carried_rule()
 * — via the SHARED SyncScopeBuildResultFixtures::et_rescan_scenario(), not a hand-copied fixture —
 * through the REAL ScannerAjax::do_build_result(), and hands back the decoded stored JSON.
 *
 * Deliberately NOT a WP_Mock\Tools\TestCase subclass: PHPUnit 9's directory test-suite loader
 * picks up every non-abstract TestCase subclass declared in a *Test.php file and runs it as
 * its own (here: empty) test case, which would silently inflate the suite's Risky count. A
 * plain class stubbing WP_Mock directly avoids that while still driving the real producer.
 *
 * Design constraint (spec §6 / task-2 brief): do NOT hand-compose the merged rules list —
 * this class only assembles the WORKER PAGE fixture (the scan input, identical to AC-1's,
 * via the shared trait); the merge itself runs inside the real do_build_result().
 *
 * Reusable: Task 4 adds fixture_b_et_run($test) beside this method following the same
 * contract — register stubs, run the real handler, capture, WP_Mock::tearDown()+setUp()
 * before returning so the caller can register its OWN stubs next (WP_Mock is process-global;
 * SyncScopeHandlersTest runs @runTestsInSeparateProcesses per Ruling B, so this brackets
 * cleanly within that one process without colliding with the handler-phase stubs).
 */
class SyncScopeBuildResultTestFixtureAccess {
    use SyncScopeBuildResultFixtures;

    /**
     * Runs the AC-1 ET-rescan scenario through the real do_build_result() and returns the
     * decoded stored JSON for job "job-et". $test is the calling test instance, used only to
     * assert the producer actually stored the JSON (fail loud rather than returning null).
     */
    public function et_rescan_json( TestCase $test ): array {
        // wp_parse_url must be live BEFORE et_rescan_scenario() runs (it calls r_orig_from(),
        // which executes the REAL CuJsonBuilder::build() -> UrlPattern::from_url ahead of
        // stub_everything_impl() below — same ordering constraint as SyncScopeBuildResultTest::setUp()).
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( (string) $u, $c ) );

        $scenario = $this->et_rescan_scenario();

        $options    = [];
        $transients = [ 'cu_scanner_r_orig_1' => $scenario['r_orig'] ];
        $this->stub_everything_impl( $scenario['rescan'], $options, $transients );

        ( new ScannerAjax() )->do_build_result( 'job-et', 'tok' );

        $test->assertArrayHasKey( 'cu_scanner_json_job-et', $options, 'the AC-1 ET-rescan producer stored the scan JSON' );
        $decoded = json_decode( (string) $options['cu_scanner_json_job-et'], true );

        // Leave WP_Mock clean for the caller to register its OWN (handler-phase) stubs next.
        WP_Mock::tearDown();
        WP_Mock::setUp();

        return $decoded;
    }

    /**
     * Final-review fold (2026-09-05) — AC-5 handler-leg helper. Runs the SAME mixed-host ET
     * rescan scenario as SyncScopeBuildResultTest::test_ac5_mixed_host_et_rescan_with_no_internal_in_scope_rules()
     * — via the SHARED SyncScopeBuildResultFixtures::mixed_host_et_scenario(), not a hand-copied
     * fixture — through the REAL ScannerAjax::do_build_result(), and hands back the decoded
     * stored JSON for job "job-mixed". Same bracketing contract as et_rescan_json() above.
     */
    public function mixed_host_et_json( TestCase $test ): array {
        // wp_parse_url must be live BEFORE mixed_host_et_scenario() runs (it calls
        // r_orig_from(), which executes the REAL CuJsonBuilder::build() -> UrlPattern::from_url
        // ahead of stub_everything_impl() below — same ordering constraint as et_rescan_json()).
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( (string) $u, $c ) );

        $scenario = $this->mixed_host_et_scenario();

        $options    = [];
        $transients = [ 'cu_scanner_r_orig_1' => $scenario['r_orig'] ];
        $this->stub_everything_impl( $scenario['rescan'], $options, $transients );

        ( new ScannerAjax() )->do_build_result( 'job-mixed', 'tok' );

        $test->assertArrayHasKey( 'cu_scanner_json_job-mixed', $options, 'the AC-5 mixed-host ET rescan producer stored the scan JSON' );
        $decoded = json_decode( (string) $options['cu_scanner_json_job-mixed'], true );

        // Leave WP_Mock clean for the caller to register its OWN (handler-phase) stubs next.
        WP_Mock::tearDown();
        WP_Mock::setUp();

        return $decoded;
    }

    /**
     * Task 4 — AC-2(c) end-to-end producer. Fixture B's ET variant (spec §5): the SAME three
     * internal pages + one external page as SyncScopeBuildResultTest::test_ac10_..., but run as
     * an ET rescan whose R_orig parent ALSO has an internal page (https://site.test/carried/)
     * that is NOT part of the rescan. That page's rule is therefore restored by the ratchet
     * (RatchetMerger::merge()'s absent_restore branch, class-ratchet-merger.php ~L410-419) and
     * lands in the stored JSON's 'rules' — a carried, out-of-scan pattern the scope filter this
     * FU adds must drop before it reaches CU.
     *
     * Buckets are the AUTHORITATIVE per-device field CuJsonBuilder::classify() reads first
     * (class-cu-json-builder.php ~L203): 'absent' on both devices -> Safe (combine()'s
     * 'absent,absent' cell); 'aggressive' on both -> Aggressive (combine()'s
     * 'aggressive,aggressive' cell). Parent and rescan use the IDENTICAL page arrays for
     * p1/p2/p3/ext (only the rescan copies add extra_time_charged), so every one of their rules
     * is 'in_r_et' in the merge walk (RatchetMerger::merge() Step 6) — re-derived by THIS rescan,
     * never restored — and only /carried/, absent from the rescan entirely, takes the
     * absent_restore path. That keeps the split fully attributable to CuJsonBuilder's own
     * classify()/combine(), never hand-composed:
     *   p1 = 1 Safe (bucket absent/absent) + 2 Aggressive (bucket aggressive/aggressive)
     *   p2 = 3 Aggressive, p3 = 1 Aggressive         => host-internal apply_safe=1 / apply_agg=6
     *   https://ext.test/x/ = 2 Aggressive           => dropped by the HOST filter
     *   https://site.test/carried/ = 1 Aggressive    => dropped by the SCOPE filter (carried, ∉ scanned_patterns)
     *
     * Same contract as et_rescan_json() above: returns ['json' => decoded stored JSON, 'payload'
     * => the live do_build_result() return], brackets WP_Mock so the caller (SyncScopeHandlersTest,
     * @runTestsInSeparateProcesses) can register its OWN handler-phase stubs next.
     */
    public function fixture_b_et_run( TestCase $test ): array {
        // wp_parse_url must be live BEFORE r_orig_from() runs (same ordering constraint as
        // et_rescan_json() above — r_orig_from() runs the REAL CuJsonBuilder::build()).
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( (string) $u, $c ) );

        $p1      = $this->page_with_buckets( 'https://site.test/p1/', [ 'p1-safe' => 'absent', 'p1-a' => 'aggressive', 'p1-b' => 'aggressive' ] );
        $p2      = $this->page_with_buckets( 'https://site.test/p2/', [ 'p2-a' => 'aggressive', 'p2-b' => 'aggressive', 'p2-c' => 'aggressive' ] );
        $p3      = $this->page_with_buckets( 'https://site.test/p3/', [ 'p3-a' => 'aggressive' ] );
        $ext     = $this->page_with_buckets( 'https://ext.test/x/', [ 'x-a' => 'aggressive', 'x-b' => 'aggressive' ] );
        $carried = $this->page_with_buckets( 'https://site.test/carried/', [ 'carried-a' => 'aggressive' ] );

        $parent = [ $p1, $p2, $p3, $ext, $carried ];
        $rescan = [
            array_merge( $p1, [ 'extra_time_charged' => true ] ),
            array_merge( $p2, [ 'extra_time_charged' => true ] ),
            array_merge( $p3, [ 'extra_time_charged' => true ] ),
            array_merge( $ext, [ 'extra_time_charged' => true ] ),
        ];

        $options    = [];
        $transients = [ 'cu_scanner_r_orig_1' => $this->r_orig_from( $parent ) ];
        $this->stub_everything_impl( $rescan, $options, $transients );

        $payload = ( new ScannerAjax() )->do_build_result( 'job-b-et', 'tok' );

        $test->assertArrayHasKey( 'cu_scanner_json_job-b-et', $options, 'the Fixture B ET producer stored the scan JSON' );
        $decoded = json_decode( (string) $options['cu_scanner_json_job-b-et'], true );

        // Leave WP_Mock clean for the caller to register its OWN (handler-phase) stubs next.
        WP_Mock::tearDown();
        WP_Mock::setUp();

        return [ 'json' => $decoded, 'payload' => $payload ];
    }
}

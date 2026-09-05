<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use CUScanner\Scanner\CuJsonBuilder;
use WP_Mock;
use WP_Mock\Tools\TestCase;

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

    /** A worker page row: assets are (handle,type,desktop,mobile); loaded=false + coverage 0 on both devices => an unload rule (aggressive by default). */
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

    private function stub_everything( array $pages ): void {
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://site.test' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
        WP_Mock::userFunction( '__' )->andReturnUsing( fn( $t, $d = null ) => $t );
        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [
            'status' => 'complete', 'total' => count( $pages ), 'completed' => count( $pages ), 'pages' => $pages, 'flags' => [],
        ] ) );
        WP_Mock::userFunction( 'get_option' )->andReturnUsing( function ( $k, $default = false ) {
            if ( 'cu_scanner_railway_url' === $k ) { return 'https://cu-scanner-railway-production.up.railway.app'; }
            if ( 'cu_scanner_api_key' === $k )     { return 'api-key-123'; }
            return array_key_exists( $k, $this->options ) ? $this->options[ $k ] : $default;   // ratchet default-ON falls through to $default (true)
        } );
        WP_Mock::userFunction( 'update_option' )->andReturnUsing( function ( $k, $v ) { $this->options[ $k ] = $v; return true; } );
        WP_Mock::userFunction( 'get_transient' )->andReturnUsing( fn( $k ) => $this->transients[ $k ] ?? false );
        WP_Mock::userFunction( 'set_transient' )->andReturnUsing( function ( $k, $v ) { $this->transients[ $k ] = $v; return true; } );
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

    private function stored_json( string $job ): array {
        $this->assertArrayHasKey( 'cu_scanner_json_' . $job, $this->options, 'do_build_result stored the scan JSON' );
        return json_decode( (string) $this->options[ 'cu_scanner_json_' . $job ], true );
    }

    // ---------------------------------------------------------------- AC-1
    public function test_ac1_et_rescan_stores_scanned_patterns_and_keeps_the_carried_rule(): void {
        $parent = [ $this->page( 'https://site.test/', [ 'home-a' ] ), $this->page( 'https://site.test/other/', [ 'other-a', 'other-b' ] ) ];
        $this->transients['cu_scanner_r_orig_1'] = $this->r_orig_from( $parent );
        // ET rescan of the home page only; extra_time_charged makes is_et_rescan() true (no marker transient needed).
        $rescan = [ $this->page( 'https://site.test/', [ 'home-a' ], [ 'extra_time_charged' => true ] ) ];
        $this->stub_everything( $rescan );

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
        // Parent: internal /other/ produced rules; rescan: internal /empty/ (asset in use => no rule) + external /x/ with rules.
        $parent = [ $this->page( 'https://site.test/other/', [ 'other-a' ] ), $this->page( 'https://site.test/empty/', [] ), $this->page( 'https://ext.test/x/', [ 'x-a' ] ) ];
        $this->transients['cu_scanner_r_orig_1'] = $this->r_orig_from( $parent );
        $used = [ 'handle' => 'used-a', 'type' => 'style', 'desktop' => [ 'loaded' => true, 'coverage' => 0.9 ], 'mobile' => [ 'loaded' => true, 'coverage' => 0.9 ] ];
        $rescan = [
            [ 'url' => 'https://site.test/empty/', 'status' => 'done', 'assets' => [ $used ], 'extra_time_charged' => true ],
            $this->page( 'https://ext.test/x/', [ 'x-a' ], [ 'extra_time_charged' => true ] ),
        ];
        $this->stub_everything( $rescan );
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

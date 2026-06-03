<?php
// tests/RatchetMergerTest.php
namespace CUScanner\Tests;

use CUScanner\Scanner\RatchetMerger;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Acceptance tests for RatchetMerger — demotion-aware union of R_orig and R_et.
 *
 * Policy (§3.4):
 *   - R_et rules pass through unconditionally (ceiling).
 *   - R_orig rules NOT already in R_et:
 *     - Benign failsafe page  → restore ALL orig rules for that page.
 *     - Validated failsafe page → drop ALL orig rules for that page.
 *     - Asset covered (coverage > 0) → drop.
 *     - demote_class='benign'    → restore.
 *     - demote_class='validated' → drop.
 *     - No demote_class / fail-closed → drop.
 *     - Asset absent from rescan entirely → restore (benign absent).
 */
class RatchetMergerTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( 'wp_parse_url' )
            ->andReturnUsing( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fixture helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build a minimal CU rule array (7-key wire format).
     *
     * @param string $handle  asset_handle
     * @param string $dev     device_type: 'all'|'desktop'|'mobile'
     * @param int    $group   1=safe, 2=aggressive
     * @param string $type    'css'|'js'
     * @param string $pat     url_pattern
     */
    private function rule(
        string $handle,
        string $dev,
        int    $group,
        string $type = 'css',
        string $pat  = 'https://s.com/p'
    ): array {
        return [
            'url_pattern'  => $pat,
            'match_type'   => 'exact',
            'asset_handle' => $handle,
            'asset_type'   => $type,
            'device_type'  => $dev,
            'group_id'     => $group,
            'source_label' => 'AA Scanner',
        ];
    }

    /**
     * Build a rescan page with a single asset.
     *
     * $asset_extra keys: 'demote_class', 'bucket_desktop', 'bucket_mobile',
     *                    'cov_desktop', 'cov_mobile'.
     */
    private function page_with_asset(
        string $url,
        string $handle,
        string $type       = 'css',
        array  $asset_extra = []
    ): array {
        $cov_d  = $asset_extra['cov_desktop']    ?? 0.0;
        $cov_m  = $asset_extra['cov_mobile']     ?? 0.0;
        $bkt_d  = $asset_extra['bucket_desktop'] ?? ( $cov_d > 0.001 ? 'needed' : ( $cov_d <= 0.0 ? 'aggressive' : 'needed' ) );
        $bkt_m  = $asset_extra['bucket_mobile']  ?? ( $cov_m > 0.001 ? 'needed' : ( $cov_m <= 0.0 ? 'aggressive' : 'needed' ) );

        $asset = [
            'handle'  => $handle,
            'type'    => $type,
            'desktop' => [ 'loaded' => true, 'coverage' => $cov_d, 'bucket' => $bkt_d ],
            'mobile'  => [ 'loaded' => true, 'coverage' => $cov_m, 'bucket' => $bkt_m ],
        ];
        if ( isset( $asset_extra['demote_class'] ) ) {
            $asset['demote_class'] = $asset_extra['demote_class'];
        }

        return [
            'url'    => $url,
            'status' => 'done',
            'assets' => [ $asset ],
        ];
    }

    /**
     * Build a rescan page that triggered a whole-page failsafe — assets may be empty.
     */
    private function page_failsafe( string $url, string $trigger, array $assets = [] ): array {
        return [
            'url'              => $url,
            'status'           => 'done',
            'assets'           => $assets,
            'failsafe_demote'  => $trigger,
        ];
    }

    /**
     * Check whether $rules contains a rule matching handle+device+group
     * (ignores url_pattern and asset_type for brevity — AC tests use a single pattern).
     */
    private function has_rule( array $rules, string $handle, string $dev, int $group ): bool {
        foreach ( $rules as $r ) {
            if (
                $r['asset_handle'] === $handle
                && $r['device_type'] === $dev
                && $r['group_id']    === $group
            ) {
                return true;
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // FAILSAFE_DEMOTE_CLASS constant + resolve_failsafe_class helper
    // ─────────────────────────────────────────────────────────────────────

    public function test_failsafe_map_benign_triggers(): void {
        $merger = new RatchetMerger();
        $this->assertSame( 'benign',    $merger->resolve_failsafe_class( 'aggressive_goto_exhausted' ) );
        $this->assertSame( 'benign',    $merger->resolve_failsafe_class( 'control_probe_failed' ) );
    }

    public function test_failsafe_map_validated_triggers(): void {
        $merger = new RatchetMerger();
        $this->assertSame( 'validated', $merger->resolve_failsafe_class( 'visual_unattributable' ) );
        $this->assertSame( 'validated', $merger->resolve_failsafe_class( 'no_offender_isolated' ) );
    }

    public function test_failsafe_map_unknown_trigger_is_fail_closed_validated(): void {
        $merger = new RatchetMerger();
        // Unknown triggers must resolve to 'validated' (fail-closed).
        $this->assertSame( 'validated', $merger->resolve_failsafe_class( 'some_unknown_trigger' ) );
        $this->assertSame( 'validated', $merger->resolve_failsafe_class( '' ) );
    }

    // ─────────────────────────────────────────────────────────────────────
    // identity_key
    // ─────────────────────────────────────────────────────────────────────

    public function test_identity_key_excludes_group_id(): void {
        $merger = new RatchetMerger();
        $r1 = $this->rule( 'h', 'desktop', 1, 'css', 'https://s.com/p' );
        $r2 = $this->rule( 'h', 'desktop', 2, 'css', 'https://s.com/p' );
        // Different group_id → same identity_key.
        $this->assertSame(
            $merger->identity_key( $r1 ),
            $merger->identity_key( $r2 )
        );
    }

    public function test_identity_key_differs_on_device(): void {
        $merger = new RatchetMerger();
        $r1 = $this->rule( 'h', 'desktop', 2 );
        $r2 = $this->rule( 'h', 'mobile',  2 );
        $this->assertNotSame( $merger->identity_key( $r1 ), $merger->identity_key( $r2 ) );
    }

    // ─────────────────────────────────────────────────────────────────────
    // explode_all / recollapse
    // ─────────────────────────────────────────────────────────────────────

    public function test_explode_all_splits_all_device_into_desktop_and_mobile(): void {
        $merger = new RatchetMerger();
        $rules  = [ $this->rule( 'h', 'all', 2 ) ];
        $out    = $merger->explode_all( $rules );
        $this->assertCount( 2, $out );
        $devices = array_column( $out, 'device_type' );
        sort( $devices );
        $this->assertSame( [ 'desktop', 'mobile' ], $devices );
    }

    public function test_explode_all_passes_through_per_device_rules(): void {
        $merger = new RatchetMerger();
        $rules  = [
            $this->rule( 'h', 'desktop', 2 ),
            $this->rule( 'h', 'mobile',  1 ),
        ];
        $out = $merger->explode_all( $rules );
        $this->assertCount( 2, $out );
    }

    public function test_recollapse_pairs_matching_legs_into_all(): void {
        $merger = new RatchetMerger();
        $rules  = [
            $this->rule( 'h', 'desktop', 2 ),
            $this->rule( 'h', 'mobile',  2 ),
        ];
        $out = $merger->recollapse( $rules );
        $this->assertCount( 1, $out );
        $this->assertSame( 'all', $out[0]['device_type'] );
        $this->assertSame( 2,     $out[0]['group_id'] );
    }

    public function test_recollapse_does_not_pair_legs_with_different_group_id(): void {
        $merger = new RatchetMerger();
        $rules  = [
            $this->rule( 'h', 'desktop', 2 ),
            $this->rule( 'h', 'mobile',  1 ), // group differs → no collapse
        ];
        $out = $merger->recollapse( $rules );
        $this->assertCount( 2, $out );
    }

    public function test_explode_then_recollapse_is_identity_for_all_rule(): void {
        $merger   = new RatchetMerger();
        $original = [ $this->rule( 'h', 'all', 2 ) ];
        $roundtrip = $merger->recollapse( $merger->explode_all( $original ) );
        $this->assertCount( 1, $roundtrip );
        $this->assertSame( 'all', $roundtrip[0]['device_type'] );
        $this->assertSame( 2,     $roundtrip[0]['group_id'] );
    }

    // ─────────────────────────────────────────────────────────────────────
    // dedupe_resolve_conflicts
    // ─────────────────────────────────────────────────────────────────────

    /**
     * AC-ETR-7 — duplicate (same handle/device, different group): keep stronger.
     */
    public function test_ac_etr_7_dedup_conflict_keeps_stronger_group(): void {
        $merger = new RatchetMerger();
        $rules  = [
            $this->rule( 'h', 'desktop', 1 ), // safe
            $this->rule( 'h', 'desktop', 2 ), // aggressive
        ];
        $out = $merger->dedupe_resolve_conflicts( $rules );
        $this->assertCount( 1, $out );
        $this->assertSame( 2, $out[0]['group_id'] );
    }

    /**
     * AC-ETR-12 — no downgrade: aggressive must never be replaced by safe.
     */
    public function test_ac_etr_12_no_downgrade_aggressive_stays(): void {
        $merger = new RatchetMerger();
        // aggressive comes FIRST, safe comes second — safe must not win.
        $rules  = [
            $this->rule( 'h', 'mobile', 2 ),
            $this->rule( 'h', 'mobile', 1 ),
        ];
        $out = $merger->dedupe_resolve_conflicts( $rules );
        $this->assertCount( 1, $out );
        $this->assertSame( 2, $out[0]['group_id'] );
    }

    public function test_dedup_preserves_unique_rules(): void {
        $merger = new RatchetMerger();
        $rules  = [
            $this->rule( 'h1', 'desktop', 2 ),
            $this->rule( 'h2', 'desktop', 2 ),
        ];
        $out = $merger->dedupe_resolve_conflicts( $rules );
        $this->assertCount( 2, $out );
    }

    // ─────────────────────────────────────────────────────────────────────
    // merge() — core AC tests
    // ─────────────────────────────────────────────────────────────────────

    /**
     * AC-ETR-1 — floor/benign demotion: orig rule is restored.
     *
     * rescan demoted the asset with demote_class='benign' on a normal page
     * (no failsafe) → the original aggressive rule must be in the final set.
     */
    public function test_ac_etr_1_benign_demotion_restores_orig_rule(): void {
        $pat     = 'https://s.com/p';
        $r_orig  = [ $this->rule( 'h', 'desktop', 2, 'css', $pat ) ];
        // Rescan: asset present but demoted (bucket='needed', cov=0.001 = sentinel → no ET rule emitted)
        // demote_class='benign' on the asset
        $rescan_pages = [
            $this->page_with_asset(
                $pat,
                'h',
                'style',
                [
                    'bucket_desktop' => 'needed',
                    'bucket_mobile'  => 'needed',
                    'cov_desktop'    => 0.001,
                    'cov_mobile'     => 0.001,
                    'demote_class'   => 'benign',
                ]
            ),
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $this->assertTrue(
            $this->has_rule( $final, 'h', 'desktop', 2 ),
            'benign demotion must restore the original desktop aggressive rule'
        );
    }

    /**
     * AC-ETR-3 — F-DEG validated demotion: orig rule must be excluded.
     *
     * rescan demoted h with demote_class='validated' (visual_diff: real break)
     * → the rule must NOT appear in final.
     */
    public function test_ac_etr_3_validated_demotion_drops_orig_rule(): void {
        $pat     = 'https://s.com/p';
        $r_orig  = [ $this->rule( 'h', 'desktop', 2, 'css', $pat ) ];
        // cov=0.001 sentinel: loaded but rescued (needed), so no ET rule from builder.
        $rescan_pages = [
            $this->page_with_asset(
                $pat,
                'h',
                'style',
                [
                    'bucket_desktop' => 'needed',
                    'bucket_mobile'  => 'needed',
                    'cov_desktop'    => 0.001,
                    'cov_mobile'     => 0.001,
                    'demote_class'   => 'validated',
                ]
            ),
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $this->assertFalse(
            $this->has_rule( $final, 'h', 'desktop', 2 ),
            'validated demotion must drop the original rule'
        );
    }

    /**
     * AC-ETR-5 — covered asset: orig rule excluded.
     *
     * rescan asset desktop coverage=40 (genuinely in use) → drop.
     */
    public function test_ac_etr_5_covered_asset_drops_orig_rule(): void {
        $pat     = 'https://s.com/p';
        $r_orig  = [ $this->rule( 'h', 'desktop', 2, 'css', $pat ) ];
        $rescan_pages = [
            $this->page_with_asset(
                $pat,
                'h',
                'style',
                [
                    'bucket_desktop' => 'needed',
                    'bucket_mobile'  => 'needed',
                    'cov_desktop'    => 40.0,
                    'cov_mobile'     => 0.0,
                    // no demote_class — covered
                ]
            ),
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $this->assertFalse(
            $this->has_rule( $final, 'h', 'desktop', 2 ),
            'covered asset must be dropped from final'
        );
    }

    /**
     * AC-ETR-10 — fail-closed: no demote_class means drop.
     */
    public function test_ac_etr_10_no_demote_class_is_fail_closed_drop(): void {
        $pat     = 'https://s.com/p';
        $r_orig  = [ $this->rule( 'h', 'desktop', 2, 'css', $pat ) ];
        // demoted (sentinel coverage) but NO demote_class → fail-closed → drop
        $rescan_pages = [
            $this->page_with_asset(
                $pat,
                'h',
                'style',
                [
                    'bucket_desktop' => 'needed',
                    'bucket_mobile'  => 'needed',
                    'cov_desktop'    => 0.001,
                    'cov_mobile'     => 0.001,
                    // intentionally no 'demote_class'
                ]
            ),
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $this->assertFalse(
            $this->has_rule( $final, 'h', 'desktop', 2 ),
            'missing demote_class must be fail-closed (drop)'
        );
    }

    /**
     * AC-ETR-4 — benign failsafe page: ALL orig rules restored.
     *
     * A:0 page (assets=[]) with failsafe_demote='control_probe_failed' (benign)
     * → both original rules must be in final.
     */
    public function test_ac_etr_4_benign_failsafe_page_restores_all_orig_rules(): void {
        $pat    = 'https://s.com/p';
        $r_orig = [
            $this->rule( 'h1', 'desktop', 2, 'css', $pat ),
            $this->rule( 'h2', 'mobile',  1, 'css', $pat ),
        ];
        $rescan_pages = [
            $this->page_failsafe( $pat, 'control_probe_failed', [] ),
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $this->assertTrue(
            $this->has_rule( $final, 'h1', 'desktop', 2 ),
            'benign failsafe must restore h1'
        );
        $this->assertTrue(
            $this->has_rule( $final, 'h2', 'mobile', 1 ),
            'benign failsafe must restore h2'
        );
    }

    /**
     * AC-ETR-11 — validated failsafe page: orig rules dropped.
     */
    public function test_ac_etr_11_validated_failsafe_page_drops_orig_rules(): void {
        $pat    = 'https://s.com/p';
        $r_orig = [ $this->rule( 'h', 'desktop', 2, 'css', $pat ) ];
        $rescan_pages = [
            $this->page_failsafe( $pat, 'visual_unattributable', [] ),
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $this->assertFalse(
            $this->has_rule( $final, 'h', 'desktop', 2 ),
            'validated failsafe must drop orig rule'
        );
    }

    /**
     * AC-ETR-2 — ceiling: orig empty; rescan ships a new aggressive rule.
     *
     * R_et rule passes through unconditionally.
     */
    public function test_ac_etr_2_ceiling_rescan_rule_passes_through(): void {
        $pat    = 'https://s.com/p';
        $r_orig = [];
        // aggressive both devices → builder emits device='all', group=2
        $rescan_pages = [
            [
                'url'    => $pat,
                'status' => 'done',
                'assets' => [ [
                    'handle'  => 'new-h',
                    'type'    => 'style',
                    'desktop' => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => 'aggressive' ],
                    'mobile'  => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => 'aggressive' ],
                ] ],
            ],
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $this->assertTrue(
            $this->has_rule( $final, 'new-h', 'all', 2 ),
            'new ET aggressive rule must be in final (ceiling)'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Additional edge cases
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Absent from rescan entirely (rescan never addressed a page/asset):
     * re-include (benign absent).
     */
    public function test_orig_rule_for_unaddressed_page_is_restored(): void {
        $r_orig       = [ $this->rule( 'h', 'desktop', 2, 'css', 'https://s.com/other' ) ];
        $rescan_pages = [
            // rescan only covers a DIFFERENT page — 'other' is unaddressed
            [
                'url'    => 'https://s.com/p',
                'status' => 'done',
                'assets' => [],
            ],
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $this->assertTrue(
            $this->has_rule( $final, 'h', 'desktop', 2 ),
            'orig rule for page not in rescan must be restored (benign absent)'
        );
    }

    /**
     * aggressive_goto_exhausted failsafe is also benign.
     */
    public function test_aggressive_goto_exhausted_failsafe_is_benign(): void {
        $pat    = 'https://s.com/p';
        $r_orig = [ $this->rule( 'h', 'desktop', 2, 'css', $pat ) ];
        $rescan_pages = [
            $this->page_failsafe( $pat, 'aggressive_goto_exhausted', [] ),
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $this->assertTrue(
            $this->has_rule( $final, 'h', 'desktop', 2 ),
            'aggressive_goto_exhausted failsafe must restore orig rule'
        );
    }

    /**
     * no_offender_isolated failsafe is validated — drops orig.
     */
    public function test_no_offender_isolated_failsafe_is_validated(): void {
        $pat    = 'https://s.com/p';
        $r_orig = [ $this->rule( 'h', 'desktop', 2, 'css', $pat ) ];
        $rescan_pages = [
            $this->page_failsafe( $pat, 'no_offender_isolated', [] ),
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $this->assertFalse(
            $this->has_rule( $final, 'h', 'desktop', 2 ),
            'no_offender_isolated failsafe must drop orig rule'
        );
    }

    /**
     * Unknown failsafe trigger → fail-closed → drops orig.
     */
    public function test_unknown_failsafe_trigger_is_fail_closed(): void {
        $pat    = 'https://s.com/p';
        $r_orig = [ $this->rule( 'h', 'desktop', 2, 'css', $pat ) ];
        $rescan_pages = [
            $this->page_failsafe( $pat, 'some_completely_unknown_trigger', [] ),
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $this->assertFalse(
            $this->has_rule( $final, 'h', 'desktop', 2 ),
            'unknown failsafe trigger must be fail-closed (drop)'
        );
    }

    /**
     * R_et rule already present: orig rule for same key is not duplicated.
     */
    public function test_merge_does_not_duplicate_rule_already_in_r_et(): void {
        $pat    = 'https://s.com/p';
        $r_orig = [ $this->rule( 'h', 'all', 2, 'css', $pat ) ];
        // rescan ships the same asset as aggressive on both → builder emits all/2
        $rescan_pages = [
            [
                'url'    => $pat,
                'status' => 'done',
                'assets' => [ [
                    'handle'  => 'h',
                    'type'    => 'style',
                    'desktop' => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => 'aggressive' ],
                    'mobile'  => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => 'aggressive' ],
                ] ],
            ],
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $matching = array_filter(
            $final,
            fn( $r ) => $r['asset_handle'] === 'h' && $r['device_type'] === 'all' && $r['group_id'] === 2
        );
        $this->assertCount( 1, $matching, 'rule already in R_et must not be duplicated' );
    }

    // ─────────────────────────────────────────────────────────────────────
    // url_to_pattern parity: RatchetMerger must stay byte-identical with
    // CuJsonBuilder — both are private copies; if one drifts, merge() silently
    // mis-aligns keys and restore/drop decisions break for all assets.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * For each URL in a representative corpus, the url_pattern that
     * RatchetMerger derives MUST equal the url_pattern CuJsonBuilder
     * emits in its built rules[0].
     *
     * Failure means the two copies have diverged and merge() will
     * never find a match between rescan keys and R_orig keys.
     */
    public function test_url_pattern_parity_with_cujsonbuilder(): void {
        $corpus = [
            'root'            => 'https://s.com/',
            'trailing-slash'  => 'https://s.com/about/',
            'query-string'    => 'https://s.com/p?ver=2',
            'uppercase'       => 'HTTPS://S.COM/Path',
            'no-trailing'     => 'https://s.com/x/y',
        ];

        // One safe-safe asset that will always produce a rule from CuJsonBuilder.
        $safe_asset = [
            'handle'  => 'parity-h',
            'type'    => 'style',
            'desktop' => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => 'aggressive' ],
            'mobile'  => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => 'aggressive' ],
        ];

        $builder = new \CUScanner\Scanner\CuJsonBuilder();
        $merger  = new RatchetMerger();

        foreach ( $corpus as $label => $url ) {
            $built = $builder->build( [
                [ 'url' => $url, 'status' => 'done', 'assets' => [ $safe_asset ] ],
            ] );

            $this->assertNotEmpty(
                $built['rules'],
                "CuJsonBuilder produced no rules for URL [{$label}]: {$url}"
            );

            $cujson_pattern  = $built['rules'][0]['url_pattern'];
            $merger_pattern  = $merger->__test_url_to_pattern( $url );

            $this->assertSame(
                $cujson_pattern,
                $merger_pattern,
                "url_to_pattern DRIFT detected for [{$label}]: {$url}\n" .
                "  CuJsonBuilder : {$cujson_pattern}\n" .
                "  RatchetMerger : {$merger_pattern}"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // recovered_by_pattern — B4
    // ─────────────────────────────────────────────────────────────────────

    /**
     * AC-ETR-B4-1 — recovered_by_pattern: benign demotion restore → count 1 for that pattern.
     *
     * One R_orig desktop rule benignly demoted (not in R_et) → recovered_by_pattern[$pat] === 1.
     */
    public function test_recovered_by_pattern_benign_restore_counts_one(): void {
        $pat    = 'https://s.com/p';
        $r_orig = [ $this->rule( 'h', 'desktop', 2, 'css', $pat ) ];
        $rescan_pages = [
            $this->page_with_asset(
                $pat,
                'h',
                'style',
                [
                    'bucket_desktop' => 'needed',
                    'bucket_mobile'  => 'needed',
                    'cov_desktop'    => 0.001,
                    'cov_mobile'     => 0.001,
                    'demote_class'   => 'benign',
                ]
            ),
        ];
        $merger = new RatchetMerger();
        $merger->merge( $r_orig, $rescan_pages );

        $this->assertArrayHasKey(
            $pat,
            $merger->recovered_by_pattern,
            'recovered_by_pattern must contain the restored pattern'
        );
        $this->assertSame(
            1,
            $merger->recovered_by_pattern[ $pat ],
            'one benign-restored leg must yield count 1'
        );
    }

    /**
     * AC-ETR-B4-2 — recovered_by_pattern: validated demotion → 0 (pattern absent).
     */
    public function test_recovered_by_pattern_validated_drop_yields_zero(): void {
        $pat    = 'https://s.com/p';
        $r_orig = [ $this->rule( 'h', 'desktop', 2, 'css', $pat ) ];
        $rescan_pages = [
            $this->page_with_asset(
                $pat,
                'h',
                'style',
                [
                    'bucket_desktop' => 'needed',
                    'bucket_mobile'  => 'needed',
                    'cov_desktop'    => 0.001,
                    'cov_mobile'     => 0.001,
                    'demote_class'   => 'validated',
                ]
            ),
        ];
        $merger = new RatchetMerger();
        $merger->merge( $r_orig, $rescan_pages );

        $this->assertSame(
            0,
            $merger->recovered_by_pattern[ $pat ] ?? 0,
            'validated drop must not increment recovered_by_pattern'
        );
    }

    /**
     * AC-ETR-B4-3 — recovered_by_pattern resets between merge() calls.
     *
     * First call restores a rule; second call on a clean scenario must not
     * carry over the count from the first call.
     */
    public function test_recovered_by_pattern_resets_between_calls(): void {
        $pat    = 'https://s.com/p';
        $r_orig = [ $this->rule( 'h', 'desktop', 2, 'css', $pat ) ];
        $rescan_benign = [
            $this->page_with_asset(
                $pat, 'h', 'style',
                [ 'bucket_desktop' => 'needed', 'bucket_mobile' => 'needed',
                  'cov_desktop' => 0.001, 'cov_mobile' => 0.001, 'demote_class' => 'benign' ]
            ),
        ];
        // Second call: validated drop — should reset the count from the first call.
        $rescan_validated = [
            $this->page_with_asset(
                $pat, 'h', 'style',
                [ 'bucket_desktop' => 'needed', 'bucket_mobile' => 'needed',
                  'cov_desktop' => 0.001, 'cov_mobile' => 0.001, 'demote_class' => 'validated' ]
            ),
        ];
        $merger = new RatchetMerger();
        $merger->merge( $r_orig, $rescan_benign );   // count = 1
        $merger->merge( $r_orig, $rescan_validated ); // must reset → count = 0

        $this->assertSame(
            0,
            $merger->recovered_by_pattern[ $pat ] ?? 0,
            'second merge() call must reset recovered_by_pattern from prior call'
        );
    }

    /**
     * merge() return passes through recollapse: desktop+mobile same group → 'all'.
     */
    public function test_merge_recollapses_desktop_mobile_into_all(): void {
        $pat    = 'https://s.com/p';
        $r_orig = [];
        // rescan: aggressive on both devices → builder emits all/2 → after
        // explode_all → desktop/2 + mobile/2 → recollapse → all/2
        $rescan_pages = [
            [
                'url'    => $pat,
                'status' => 'done',
                'assets' => [ [
                    'handle'  => 'h',
                    'type'    => 'style',
                    'desktop' => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => 'aggressive' ],
                    'mobile'  => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => 'aggressive' ],
                ] ],
            ],
        ];
        $merger = new RatchetMerger();
        $final  = $merger->merge( $r_orig, $rescan_pages );
        $this->assertTrue(
            $this->has_rule( $final, 'h', 'all', 2 ),
            'merge output must recollapse matching desktop+mobile legs into all'
        );
    }
}

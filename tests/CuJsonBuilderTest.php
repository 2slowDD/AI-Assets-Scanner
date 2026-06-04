<?php
// tests/CuJsonBuilderTest.php
namespace CUScanner\Tests;

use CUScanner\Scanner\CuJsonBuilder;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class CuJsonBuilderTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
    }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    private function make_asset( string $handle, string $type, bool $loaded_desktop, float $cov_desktop, bool $loaded_mobile, float $cov_mobile ): array {
        return [
            'handle'  => $handle,
            'type'    => $type,
            'desktop' => [ 'loaded' => $loaded_desktop, 'coverage' => $cov_desktop ],
            'mobile'  => [ 'loaded' => $loaded_mobile,  'coverage' => $cov_mobile  ],
        ];
    }

    private function make_page( string $url, array $assets ): array {
        return [ 'url' => $url, 'status' => 'done', 'assets' => $assets ];
    }

    public function test_safe_safe_produces_one_rule_device_all(): void {
        $pages  = [ $this->make_page( 'https://site.com/about/', [
            $this->make_asset( 'plugin-style', 'style', false, 0.0, false, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $rules  = $output['rules'];
        $this->assertCount( 1, $rules );
        $this->assertSame( 'all', $rules[0]['device_type'] );
        $this->assertSame( 1, $rules[0]['group_id'] ); // Safe
    }

    public function test_by_page_tallies_and_reconciliation(): void {
        $pages = [
            $this->make_page( 'https://x/a', [
                $this->make_asset( 'h1', 'style',  false, 0.0, false, 0.0 ), // absent,absent -> safe (all)
                $this->make_asset( 'h2', 'script', true,  0.0, true,  0.0 ), // aggressive,aggressive -> agg (all)
                $this->make_asset( 'h3', 'style',  true,  0.5, true,  0.5 ), // needed,needed -> no rule
            ] ),
            [ 'url' => 'https://x/b', 'status' => 'error', 'assets' => [] ], // skipped by build()
        ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertArrayHasKey( 'by_page', $output );
        $this->assertSame( [ 'safe' => 1, 'aggressive' => 1, 'needed' => 1 ], $output['by_page'][0] );
        $this->assertArrayNotHasKey( 1, $output['by_page'] ); // error page absent from by_page
        $safe = count( array_filter( $output['rules'], fn( $r ) => 1 === $r['group_id'] ) );
        $agg  = count( array_filter( $output['rules'], fn( $r ) => 2 === $r['group_id'] ) );
        $this->assertSame( $safe, array_sum( array_column( $output['by_page'], 'safe' ) ) );
        $this->assertSame( $agg,  array_sum( array_column( $output['by_page'], 'aggressive' ) ) );
    }

    public function test_by_page_reconciles_with_phase2a_and_blocked_device(): void {
        // absent,needed normally emits safe-desktop under Phase 2a — but a BLOCKED
        // desktop must suppress it; the per-page tally must match that (no drift).
        $pages = [ [
            'url'            => 'https://x/c',
            'status'         => 'done',
            'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ] ],
            'assets'         => [ $this->make_asset( 'h', 'style', false, 0.0, true, 0.5 ) ], // absent,needed
        ] ];
        $flags  = [ 'combine_asymmetric_absent_enabled' => true, 'visual_diff_enabled' => true ];
        $output = ( new CuJsonBuilder() )->build( $pages, $flags );
        $this->assertSame( [ 'safe' => 0, 'aggressive' => 0, 'needed' => 1 ], $output['by_page'][0] );
        $this->assertCount( 0, $output['rules'] );
        $this->assertSame( 0, array_sum( array_column( $output['by_page'], 'safe' ) ) );
    }

    public function test_aggressive_aggressive_produces_one_rule_device_all(): void {
        $pages = [ $this->make_page( 'https://site.com/shop/', [
            $this->make_asset( 'slider-js', 'script', true, 0.0, true, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $rules  = $output['rules'];
        $this->assertCount( 1, $rules );
        $this->assertSame( 'all', $rules[0]['device_type'] );
        $this->assertSame( 2, $rules[0]['group_id'] ); // Aggressive
    }

    public function test_absent_aggressive_drops_desktop_safe_keeps_mobile_aggressive(): void {
        // 2026-04-25: under the new classifier, asymmetric 'absent' (one device
        // says !loaded, the other says loaded with coverage signal) drops the
        // absent side as unreliable and emits only the loaded side's rule.
        // Prevents false-Safe push that would unload assets Playwright missed
        // due to coverage-tracking timing on the cold pass.
        $pages = [ $this->make_page( 'https://site.com/home/', [
            $this->make_asset( 'plugin-css', 'style', false, 0.0, true, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $rules  = $output['rules'];
        $this->assertCount( 1, $rules );
        $this->assertSame( 'mobile', $rules[0]['device_type'] );
        $this->assertSame( 2, $rules[0]['group_id'] ); // Aggressive only — desktop 'absent' dropped.
    }

    public function test_absent_needed_produces_no_rule(): void {
        // 2026-04-25: when one device says !loaded and the other says needed
        // (loaded with positive coverage), the absent side is unreliable and
        // dropped entirely. No rule is emitted — neither a wrong Safe nor an
        // aggressive that doesn't apply.
        $pages = [ $this->make_page( 'https://site.com/home/', [
            $this->make_asset( 'eb-block-style-863', 'style', false, 0.0, true, 0.5 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertCount( 0, $output['rules'] );
    }

    public function test_needed_absent_produces_no_rule(): void {
        // 2026-04-25: mirror of absent_needed — desktop loaded-and-used while
        // mobile reports !loaded. Mobile's absent could be coverage-tracking
        // timing; drop entirely rather than emit a wrong mobile-Safe rule.
        $pages = [ $this->make_page( 'https://site.com/home/', [
            $this->make_asset( 'eb-block-style-863', 'style', true, 0.5, false, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertCount( 0, $output['rules'] );
    }

    public function test_needed_needed_produces_no_rule(): void {
        $pages = [ $this->make_page( 'https://site.com/page/', [
            $this->make_asset( 'theme-style', 'style', true, 0.8, true, 0.7 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertCount( 0, $output['rules'] );
    }

    public function test_errored_pages_are_skipped(): void {
        $pages = [
            [ 'url' => 'https://site.com/about/', 'status' => 'error', 'assets' => [] ],
        ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertCount( 0, $output['rules'] );
    }

    public function test_output_has_correct_groups(): void {
        $output = ( new CuJsonBuilder() )->build( [] );
        $this->assertCount( 2, $output['groups'] );
        $this->assertSame( 'AA Scanner — Safe', $output['groups'][0]['name'] );
        $this->assertSame( 'AA Scanner — Aggressive', $output['groups'][1]['name'] );
    }

    public function test_output_version_field_is_correct(): void {
        $output = ( new CuJsonBuilder() )->build( [] );
        $this->assertSame( '1.4.1', $output['version'] );
    }

    public function test_url_pattern_is_full_normalized_url_without_query_or_trailing_slash(): void {
        $pages = [ $this->make_page( 'https://site.com/about/?nowprocket&nowpcu', [
            $this->make_asset( 'p-style', 'style', false, 0.0, false, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertSame( 'https://site.com/about', $output['rules'][0]['url_pattern'] );
    }

    public function test_url_pattern_root_keeps_trailing_slash(): void {
        $pages = [ $this->make_page( 'https://site.com/?nowprocket&nowpcu', [
            $this->make_asset( 'p-style', 'style', false, 0.0, false, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertSame( 'https://site.com/', $output['rules'][0]['url_pattern'] );
    }

    public function test_rule_fields_match_code_unloader_format(): void {
        $pages = [ $this->make_page( 'https://site.com/blog/', [
            $this->make_asset( 'my-style', 'style', false, 0.0, false, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $rule   = $output['rules'][0];
        $this->assertArrayHasKey( 'asset_handle', $rule );
        $this->assertArrayHasKey( 'match_type',   $rule );
        $this->assertArrayHasKey( 'source_label', $rule );
        $this->assertArrayNotHasKey( 'handle', $rule );
        $this->assertSame( 'my-style', $rule['asset_handle'] );
        $this->assertSame( 'exact',    $rule['match_type'] );
        $this->assertSame( 'css',      $rule['asset_type'] ); // 'style' → 'css'
    }

    public function test_script_asset_type_maps_to_js(): void {
        $pages = [ $this->make_page( 'https://site.com/blog/', [
            $this->make_asset( 'my-js', 'script', true, 0.0, true, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertSame( 'js', $output['rules'][0]['asset_type'] );
    }

    /**
     * Build an asset whose per-device payload includes the bucket field —
     * the new authoritative classification signal emitted by Railway scanner.
     */
    private function make_asset_with_bucket( string $handle, string $type, bool $loaded_desktop, float $cov_desktop, string $bucket_desktop, bool $loaded_mobile, float $cov_mobile, string $bucket_mobile ): array {
        return [
            'handle'  => $handle,
            'type'    => $type,
            'desktop' => [ 'loaded' => $loaded_desktop, 'coverage' => $cov_desktop, 'bucket' => $bucket_desktop ],
            'mobile'  => [ 'loaded' => $loaded_mobile,  'coverage' => $cov_mobile,  'bucket' => $bucket_mobile  ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2026-05-03 — Bucket field is authoritative classification signal
    // ─────────────────────────────────────────────────────────────────────

    public function test_bucket_field_overrides_legacy_loaded_check_for_phase_a_demoted_asset(): void {
        // Repro: essential-blocks-blocks-localize on wpservice.pro home.
        // Phase A on Railway demotes inline-only handle (catches console error
        // when stripping breaks consumer); wire format encodes RESCUED_SENTINEL
        // as coverage:0.001. Without bucket-passthrough, classifier short-
        // circuits on !loaded → 'absent' → safe rule emitted → consumer breaks.
        // With bucket field, 'needed' wins regardless of loaded.
        $pages = [ $this->make_page( 'https://site.com/home/', [
            $this->make_asset_with_bucket(
                'essential-blocks-blocks-localize', 'script',
                false, 0.001, 'needed',   // desktop: !loaded but rescued
                false, 0.001, 'needed'    // mobile:  !loaded but rescued
            ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertCount( 0, $output['rules'], 'Phase A-demoted handle must NOT produce a safe rule' );
    }

    public function test_bucket_field_overrides_legacy_coverage_zero_for_phase_b_demoted_asset(): void {
        // Phase B demotion of an aggressive offender (e.g. verge): coverage
        // was 0, verifier confirmed stripping breaks consumer. Wire format
        // encodes as coverage:0.001 + bucket:'needed'.
        $pages = [ $this->make_page( 'https://site.com/home/', [
            $this->make_asset_with_bucket(
                'verge', 'script',
                true, 0.001, 'needed',   // desktop: loaded, rescued
                true, 0.001, 'needed'    // mobile:  loaded, rescued
            ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertCount( 0, $output['rules'], 'Phase B-demoted aggressive offender must NOT produce any rule' );
    }

    public function test_bucket_field_passes_through_aggressive_to_emit_aggressive_rule(): void {
        $pages = [ $this->make_page( 'https://site.com/home/', [
            $this->make_asset_with_bucket(
                'agg-handle', 'script',
                true, 0.0, 'aggressive',
                true, 0.0, 'aggressive'
            ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertCount( 1, $output['rules'] );
        $this->assertSame( 2, $output['rules'][0]['group_id'] );
        $this->assertSame( 'all', $output['rules'][0]['device_type'] );
    }

    public function test_bucket_field_passes_through_absent_both_devices_to_emit_safe_rule(): void {
        // Inline-only handle that was NOT demoted (no Phase A trigger) — both
        // devices !loaded, no rescue. Bucket is 'absent' on both. Combined
        // result: safe rule (existing behavior).
        $pages = [ $this->make_page( 'https://site.com/home/', [
            $this->make_asset_with_bucket(
                'clean-inline', 'script',
                false, 0.0, 'absent',
                false, 0.0, 'absent'
            ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertCount( 1, $output['rules'] );
        $this->assertSame( 1, $output['rules'][0]['group_id'] ); // Safe
    }

    public function test_unknown_bucket_value_falls_back_to_legacy_loaded_check(): void {
        // Defense-in-depth: if a future Railway version sends an unknown bucket
        // value (typo, new enum we don't recognize, tampering), we should NOT
        // trust it. Fall back to legacy {loaded, coverage} derivation.
        $pages = [ $this->make_page( 'https://site.com/home/', [
            $this->make_asset_with_bucket(
                'mystery-handle', 'script',
                true, 0.0, 'definitely-not-a-real-bucket-value',
                true, 0.0, 'definitely-not-a-real-bucket-value'
            ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        // Legacy path: loaded=true, coverage=0 → aggressive on both → aggressive rule.
        $this->assertCount( 1, $output['rules'] );
        $this->assertSame( 2, $output['rules'][0]['group_id'] );
    }

    public function test_missing_bucket_field_falls_back_to_legacy_loaded_check(): void {
        // Older Railway versions (pre-bucket-emission) won't have the field.
        // make_asset() (without _with_bucket) emits payload without bucket.
        // Verify legacy fallback still produces correct rules for that case.
        $pages = [ $this->make_page( 'https://site.com/home/', [
            $this->make_asset( 'old-format-handle', 'script', true, 0.0, true, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertCount( 1, $output['rules'] );
        $this->assertSame( 2, $output['rules'][0]['group_id'] ); // aggressive,aggressive → all,aggressive
    }

    // ─────────────────────────────────────────────────────────────────────
    // Phase 2a — Asymmetric-absent unblock (AC-V9a-1 / 2 / 3 / 7)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Helper: set bucket directly (authoritative classification field) to
     * exercise the combine() cell-shape map without going through classify()'s
     * {loaded, coverage} derivation. Mirrors make_asset_with_bucket() but
     * omits the loaded/coverage fields to make Phase 2a test intent clear.
     */
    private function make_asset_with_buckets( string $handle, string $type, string $desktop_bucket, string $mobile_bucket ): array {
        return [
            'handle'  => $handle,
            'type'    => $type,
            'desktop' => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => $desktop_bucket ],
            'mobile'  => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => $mobile_bucket  ],
        ];
    }

    // AC-V9a-1 / AC-V9a-2 (asymmetric-absent EMITS a safe rule) removed 2026-06-04:
    // the asymmetric emit is now disabled (PHASE2A_ASYMMETRIC_SAFE_ENABLED=false).
    // The reverted behavior is covered by test_asymmetric_absent_needed_emits_no_rule_*
    // / test_asymmetric_needed_absent_emits_no_rule_* / test_multi_asymmetric_absent_*.
    // Restore these ACs when the worker ET desktop-pass fix (FU-ET-DESKTOP-ABSENT) re-enables it.

    /** AC-V9a-3: flag off → asymmetric-absent cells emit no rule. */
    public function test_phase2a_off_emits_no_rule_for_asymmetric_absent_cells(): void {
        $pages  = [ $this->make_page( 'https://site.com/', [
            $this->make_asset_with_buckets( 'eb-block-style', 'style', 'absent', 'needed' ),
            $this->make_asset_with_buckets( 'wc-blocks-style', 'style', 'needed', 'absent' ),
        ] ) ];
        $flags_off = [ 'combine_asymmetric_absent_enabled' => false, 'visual_diff_enabled' => true ];
        $output    = ( new CuJsonBuilder() )->build( $pages, $flags_off );
        $this->assertCount( 0, $output['rules'] );
    }

    /** AC-V9a-7: structural guard — Phase 2a enabled BUT visual_diff off → NO emission. */
    public function test_phase2a_structural_guard_blocks_emission_when_visual_diff_off(): void {
        $pages  = [ $this->make_page( 'https://site.com/', [
            $this->make_asset_with_buckets( 'eb-block-style', 'style', 'absent', 'needed' ),
        ] ) ];
        // Phase 2a enabled BUT visual_diff disabled → structural guard NO-OPs Phase 2a
        $flags  = [ 'combine_asymmetric_absent_enabled' => true, 'visual_diff_enabled' => false ];
        $output = ( new CuJsonBuilder() )->build( $pages, $flags );
        $this->assertCount( 0, $output['rules'] );
    }

    /** D5 safety invariant: missing flags → both default false → no Phase 2a emission. */
    public function test_phase2a_missing_flags_default_false_safety_invariant(): void {
        $pages  = [ $this->make_page( 'https://site.com/', [
            $this->make_asset_with_buckets( 'eb-block-style', 'style', 'absent', 'needed' ),
        ] ) ];
        // Empty flags array → both default to false → no Phase 2a emission
        // (D5 + Rule 1 untrusted-input safety invariant).
        $output = ( new CuJsonBuilder() )->build( $pages, [] );
        $this->assertCount( 0, $output['rules'] );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2026-06-04 — Asymmetric-absent REVERTED to dual-device-confirmation.
    // An ET-rescan desktop cold-pass can spuriously report a present+used asset
    // as 'absent' on ONE device (scan 9fabc6ec8edc: 18 site-wide scripts —
    // jquery-migrate, woocommerce, wc-add-to-cart, … — flipped needed→absent on
    // desktop vs a clean non-ET baseline). A single-device 'absent' emit ships an
    // UNVALIDATED safe-unload of a live asset (desktop F-DEG); the visual-diff
    // backstop can't catch it because the worker believes the asset is absent.
    // Restores the 2026-04-25 invariant: only absent,absent (BOTH devices
    // confirm) may yield a Safe rule. Re-enable the Phase-2a asymmetric emit only
    // after the worker ET desktop-pass reliability fix (FU-ET-DESKTOP-ABSENT) lands.
    // ─────────────────────────────────────────────────────────────────────

    /** Regression (scan 9fabc6ec8edc): absent,needed + both flags on + no broken devices → NO rule. */
    public function test_asymmetric_absent_needed_emits_no_rule_after_2026_06_04_revert(): void {
        $pages  = [ $this->make_page( 'https://site.com/', [
            $this->make_asset_with_buckets( 'jquery-migrate', 'script', 'absent', 'needed' ),
        ] ) ];
        $flags  = [ 'combine_asymmetric_absent_enabled' => true, 'visual_diff_enabled' => true ];
        $output = ( new CuJsonBuilder() )->build( $pages, $flags );
        $this->assertCount( 0, $output['rules'], 'asymmetric absent,needed must NOT emit a safe rule (single-device absent unreliable)' );
    }

    /** Mirror: needed,absent + both flags on → NO rule. */
    public function test_asymmetric_needed_absent_emits_no_rule_after_2026_06_04_revert(): void {
        $pages  = [ $this->make_page( 'https://site.com/', [
            $this->make_asset_with_buckets( 'wc-blocks-style', 'style', 'needed', 'absent' ),
        ] ) ];
        $flags  = [ 'combine_asymmetric_absent_enabled' => true, 'visual_diff_enabled' => true ];
        $output = ( new CuJsonBuilder() )->build( $pages, $flags );
        $this->assertCount( 0, $output['rules'] );
    }

    /** Production repro: a page where the ET desktop pass spuriously dropped many
     *  present+used assets to absent,needed must yield S:0 (not the buggy S:N). */
    public function test_multi_asymmetric_absent_yields_zero_safe_after_revert(): void {
        $handles = [ 'jquery-migrate', 'woocommerce', 'wc-add-to-cart', 'wc-jquery-blockui', 'generate-menu' ];
        $assets  = array_map( fn( $h ) => $this->make_asset_with_buckets( $h, 'script', 'absent', 'needed' ), $handles );
        $pages   = [ $this->make_page( 'https://site.com/', $assets ) ];
        $flags   = [ 'combine_asymmetric_absent_enabled' => true, 'visual_diff_enabled' => true ];
        $output  = ( new CuJsonBuilder() )->build( $pages, $flags );
        $this->assertCount( 0, $output['rules'] );
        $this->assertSame( [ 'safe' => 0, 'aggressive' => 0, 'needed' => 5 ], $output['by_page'][0] );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Phase 2a broken-device guard (AC-G1 / G3 / G4) — 2026-05-20
    // Suppress the per-device safe emit for a device whose probe was BLOCKED
    // (its 'absent' reading is an artifact, not a true negative).
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Helper: page carrying a `broken_devices` array (untrusted Railway shape:
     * [{device, is_broken, reason, http_status, body_bytes}]).
     */
    private function make_page_with_broken_devices( string $url, array $assets, $broken_devices ): array {
        return [ 'url' => $url, 'status' => 'done', 'assets' => $assets, 'broken_devices' => $broken_devices ];
    }

    /** AC-G1 (a): desktop blocked + absent,needed + both flags on → NO safe-desktop rule. */
    public function test_phase2a_guard_suppresses_safe_desktop_when_desktop_blocked(): void {
        $pages  = [ $this->make_page_with_broken_devices( 'https://site.com/', [
            $this->make_asset_with_buckets( 'eb-block-style', 'style', 'absent', 'needed' ),
        ], [ [ 'device' => 'desktop', 'is_broken' => true, 'reason' => 'tier1_http_4xx' ] ] ) ];
        $flags  = [ 'combine_asymmetric_absent_enabled' => true, 'visual_diff_enabled' => true ];
        $output = ( new CuJsonBuilder() )->build( $pages, $flags );
        $this->assertCount( 0, $output['rules'], 'blocked desktop must suppress the safe-desktop emit' );
    }

    /** AC-G1 (b): mobile blocked + needed,absent + both flags on → NO safe-mobile rule. */
    public function test_phase2a_guard_suppresses_safe_mobile_when_mobile_blocked(): void {
        $pages  = [ $this->make_page_with_broken_devices( 'https://site.com/', [
            $this->make_asset_with_buckets( 'wc-blocks-style', 'style', 'needed', 'absent' ),
        ], [ [ 'device' => 'mobile', 'is_broken' => true, 'reason' => 'tier1_http_4xx' ] ] ) ];
        $flags  = [ 'combine_asymmetric_absent_enabled' => true, 'visual_diff_enabled' => true ];
        $output = ( new CuJsonBuilder() )->build( $pages, $flags );
        $this->assertCount( 0, $output['rules'], 'blocked mobile must suppress the safe-mobile emit' );
    }

    // AC-G4 control (c) removed 2026-06-04: with the asymmetric emit disabled,
    // a clean no-broken-devices absent,needed page emits nothing (covered by
    // test_asymmetric_absent_needed_emits_no_rule_after_2026_06_04_revert).

    /** AC-G3 (d): desktop blocked but cells are absent,absent + aggressive,needed → those rules UNCHANGED. */
    public function test_phase2a_guard_leaves_non_phase2a_cells_unchanged_when_desktop_blocked(): void {
        $pages  = [ $this->make_page_with_broken_devices( 'https://site.com/', [
            $this->make_asset_with_buckets( 'safe-all',  'style',  'absent',     'absent' ), // → safe-all
            $this->make_asset_with_buckets( 'agg-needed', 'script', 'aggressive', 'needed' ), // → aggressive-desktop
        ], [ [ 'device' => 'desktop', 'is_broken' => true, 'reason' => 'tier1_http_4xx' ] ] ) ];
        $flags  = [ 'combine_asymmetric_absent_enabled' => true, 'visual_diff_enabled' => true ];
        $output = ( new CuJsonBuilder() )->build( $pages, $flags );
        $rules  = $output['rules'];
        $this->assertCount( 2, $rules, 'guard touches only the two Phase-2a cells' );
        // absent,absent → all/safe
        $this->assertSame( 'all', $rules[0]['device_type'] );
        $this->assertSame( 1, $rules[0]['group_id'] );
        // aggressive,needed → desktop/aggressive
        $this->assertSame( 'desktop', $rules[1]['device_type'] );
        $this->assertSame( 2, $rules[1]['group_id'] );
    }

    // AC-G4 (e) malformed-broken-devices test removed 2026-06-04: it asserted the
    // not-blocked → emit path, which is now always no-emit (asymmetric disabled).
    // The blocked_devices() malformed-input parser is exercised again when the
    // emit is re-enabled (FU-ET-DESKTOP-ABSENT). The blocked-suppression tests
    // (test_phase2a_guard_suppresses_safe_*) remain and still assert 0 rules.

    // ─────────────────────────────────────────────────────────────────────
    // AC-OR-22 — origin_unavailable pages skipped (Task B3)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A page whose status is 'origin_unavailable' must be skipped entirely —
     * no entry in by_page (mirrors the 'error' skip at line 26).
     */
    public function test_build_skips_origin_unavailable_page(): void {
        $pages = [ [ 'url' => 'https://x/', 'status' => 'origin_unavailable', 'assets' => [] ] ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertArrayNotHasKey( 0, $output['by_page'], 'origin_unavailable page must not appear in by_page tally' );
        $this->assertCount( 0, $output['rules'], 'origin_unavailable page must not produce any rules' );
    }

    /** Other cells unchanged across all flag combinations. */
    public function test_phase2a_other_cells_unchanged_across_flag_states(): void {
        // 'absent,absent' → safe-all (today + Phase 2a, all flag combos)
        $pages_aa = [ $this->make_page( 'https://site.com/', [
            $this->make_asset_with_buckets( 'h1', 'style', 'absent', 'absent' ),
        ] ) ];
        foreach ( [
            [ 'combine_asymmetric_absent_enabled' => false, 'visual_diff_enabled' => false ],
            [ 'combine_asymmetric_absent_enabled' => false, 'visual_diff_enabled' => true ],
            [ 'combine_asymmetric_absent_enabled' => true,  'visual_diff_enabled' => false ],
            [ 'combine_asymmetric_absent_enabled' => true,  'visual_diff_enabled' => true ],
        ] as $flags ) {
            $output = ( new CuJsonBuilder() )->build( $pages_aa, $flags );
            $this->assertCount( 1, $output['rules'] );
            $this->assertSame( 'all', $output['rules'][0]['device_type'] );
            $this->assertSame( 1, $output['rules'][0]['group_id'] );
        }
    }
}

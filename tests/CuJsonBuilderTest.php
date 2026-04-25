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
        $this->assertSame( 'CU Scanner — Safe', $output['groups'][0]['name'] );
        $this->assertSame( 'CU Scanner — Aggressive', $output['groups'][1]['name'] );
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
}

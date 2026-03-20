<?php
// tests/CuJsonBuilderTest.php
namespace CUScanner\Tests;

use CUScanner\Scanner\CuJsonBuilder;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class CuJsonBuilderTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
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
        $pages  = [ $this->make_page( '/about/', [
            $this->make_asset( 'plugin-style', 'style', false, 0.0, false, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $rules  = $output['rules'];
        $this->assertCount( 1, $rules );
        $this->assertSame( 'all', $rules[0]['device_type'] );
        $this->assertSame( 1, $rules[0]['group_id'] ); // Safe
    }

    public function test_aggressive_aggressive_produces_one_rule_device_all(): void {
        $pages = [ $this->make_page( '/shop/', [
            $this->make_asset( 'slider-js', 'script', true, 0.0, true, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $rules  = $output['rules'];
        $this->assertCount( 1, $rules );
        $this->assertSame( 'all', $rules[0]['device_type'] );
        $this->assertSame( 2, $rules[0]['group_id'] ); // Aggressive
    }

    public function test_safe_aggressive_produces_two_rules(): void {
        $pages = [ $this->make_page( '/home/', [
            $this->make_asset( 'plugin-css', 'style', false, 0.0, true, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $rules  = $output['rules'];
        $this->assertCount( 2, $rules );
        $device_types = array_column( $rules, 'device_type' );
        $this->assertContains( 'desktop', $device_types );
        $this->assertContains( 'mobile', $device_types );
    }

    public function test_needed_needed_produces_no_rule(): void {
        $pages = [ $this->make_page( '/page/', [
            $this->make_asset( 'theme-style', 'style', true, 0.8, true, 0.7 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertCount( 0, $output['rules'] );
    }

    public function test_errored_pages_are_skipped(): void {
        $pages = [
            [ 'url' => '/about/', 'status' => 'error', 'assets' => [] ],
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
        $this->assertSame( '1.3.6', $output['version'] );
    }

    public function test_url_pattern_strips_domain(): void {
        $pages = [ $this->make_page( 'https://site.com/about/', [
            $this->make_asset( 'p-style', 'style', false, 0.0, false, 0.0 ),
        ] ) ];
        $output = ( new CuJsonBuilder() )->build( $pages );
        $this->assertSame( '/about/', $output['rules'][0]['url_pattern'] );
    }
}

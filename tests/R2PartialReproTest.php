<?php
// tests/R2PartialReproTest.php
// Regression for the AC-R2-LIVE user_cancel "stuck / no banner" bug (2026-06-19).
// A partial scan's get_status() returns the unreached slots as
// { index, status:'pending', assets:[] } (NO 'url'). do_build_result fed the FULL pages[]
// into the build path, which is written for COMPLETE scans (all pages 'done', url+assets):
//   - CuJsonBuilder::build threw "Undefined array key 'url'" → build_result 500 → JS stuck.
//   - billable_credit_total counted each pending slot as 1 credit → a 3-of-13 partial
//     recorded credits_used = 13 (History "Partial — 13 credits charged") vs the 3 charged.
// Fix: do_build_result now calls ScannerAjax::filter_real_pages() to drop the placeholders
// before building, so a partial builds from exactly the pages that ran.
namespace CUScanner\Tests;

use CUScanner\Scanner\CuJsonBuilder;
use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class R2PartialReproTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
        WP_Mock::userFunction( '__' )->andReturnUsing( fn( $t, $d = null ) => $t );
    }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    private function done_page( string $url ): array {
        return [
            'url'    => $url,
            'status' => 'done',
            'assets' => [
                [ 'handle' => 'h-' . md5( $url ), 'type' => 'style',
                  'desktop' => [ 'loaded' => false, 'coverage' => 0.0 ],
                  'mobile'  => [ 'loaded' => false, 'coverage' => 0.0 ] ],
            ],
        ];
    }

    // Exactly what JobStore.getAllPageResults pushes for an unreached slot (no 'url').
    private function pending_slot( int $i ): array {
        return [ 'index' => $i, 'status' => 'pending', 'assets' => [] ];
    }

    private function partial_pages(): array {
        $pages = [];
        for ( $i = 0; $i < 3; $i++ )  { $pages[] = $this->done_page( "https://site.com/p$i/" ); }
        for ( $i = 3; $i < 13; $i++ ) { $pages[] = $this->pending_slot( $i ); }
        return $pages; // 3 done + 10 pending = the user_cancel-at-3-of-13 shape
    }

    public function test_filter_real_pages_drops_pending_slots(): void {
        $real = ScannerAjax::filter_real_pages( $this->partial_pages() );
        $this->assertCount( 3, $real, 'expected only the 3 pages that actually ran' );
        foreach ( $real as $p ) {
            $this->assertArrayHasKey( 'url', $p );
            $this->assertSame( 'done', $p['status'] );
        }
    }

    public function test_build_succeeds_on_filtered_partial(): void {
        // Pre-fix this threw "Undefined array key 'url'" (class-cu-json-builder.php:40).
        $real = ScannerAjax::filter_real_pages( $this->partial_pages() );
        $out  = ( new CuJsonBuilder() )->build( $real, [] );
        $this->assertArrayHasKey( 'rules', $out );
        $this->assertArrayHasKey( 'by_page', $out );
    }

    public function test_billable_credit_total_filtered_is_3_unfiltered_is_13(): void {
        $partial = $this->partial_pages();
        // The bug: unfiltered, every pending slot classifies as 1 credit.
        $this->assertSame( 13, ScannerAjax::billable_credit_total( $partial ), 'pre-filter miscount (the bug)' );
        // The fix: filtered, only the 3 real pages are billed (matches the SaaS charge).
        $this->assertSame( 3, ScannerAjax::billable_credit_total( ScannerAjax::filter_real_pages( $partial ) ), 'post-filter correct charge' );
    }

    public function test_filter_is_noop_on_a_complete_scan(): void {
        // A complete scan has no placeholders → filter must not drop anything (regression).
        $complete = [ $this->done_page( 'https://site.com/a/' ), $this->done_page( 'https://site.com/b/' ) ];
        $this->assertCount( 2, ScannerAjax::filter_real_pages( $complete ) );
    }
}

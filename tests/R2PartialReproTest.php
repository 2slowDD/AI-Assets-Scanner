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

    // --- 1.7.43b: cut-off (in-flight-when-cancelled) pages labeled "Cancelled", not "OK". ---

    public function test_build_pages_labels_cutoff_as_cancelled_on_partial(): void {
        $pages = [
            // genuine — has captured assets (a real page lists assets even when all are needed)
            [ 'url' => 'https://x/done', 'status' => 'done', 'assets' => [ [ 'handle' => 'h', 'type' => 'style' ] ] ],
            // cut-off — marked done by the worker but zero assets captured
            [ 'url' => 'https://x/cut',  'status' => 'done', 'assets' => [] ],
        ];
        $rows = \AIAS_Scan_Status::build_pages( $pages, [], true ); // is_partial = true
        $this->assertNotSame( 'cancelled', $rows[0]['status_class'], 'genuine page must not be relabeled' );
        $this->assertSame( 'cancelled', $rows[1]['status_class'], 'cut-off page must be Cancelled' );
        $this->assertSame( 0, $rows[1]['credits'], 'cut-off page must not be billed' );
    }

    public function test_build_pages_complete_scan_does_not_relabel_empty_page(): void {
        // On a COMPLETE scan a 0-asset page is genuinely empty — keep classify's result, not "Cancelled".
        $pages = [ [ 'url' => 'https://x/empty', 'status' => 'done', 'assets' => [] ] ];
        $rows  = \AIAS_Scan_Status::build_pages( $pages, [], false ); // is_partial = false
        $this->assertNotSame( 'cancelled', $rows[0]['status_class'] );
    }
}

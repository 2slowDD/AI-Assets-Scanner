<?php
// tests/NooptCreditTest.php
// TDD for Task A12 — noopt-aware single-source per-URL credit (FU-NOOPT-ZERO-CREDIT).
// Covers AIAS_Scan_Status::page_credit() and ScannerAjax::billable_credit_total() noopt path.
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class NooptCreditTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        // page_credit() calls classify() which calls __() for labels. Stub as identity.
        WP_Mock::userFunction( '__' )->andReturnUsing( fn( $t, $d = null ) => $t );
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // --- helpers ---

    /** A completed page with assets (no broken_devices → ok). */
    private function ok_page( array $extra = [] ): array {
        return array_merge( [
            'status'         => 'done',
            'assets'         => [ [ 'handle' => 'h1', 'type' => 'style' ] ],
            'broken_devices' => [],
        ], $extra );
    }

    private function error_page(): array {
        return [ 'status' => 'error', 'broken_devices' => [] ];
    }

    private function skipped_page(): array {
        return [ 'status' => 'origin_unavailable' ];
    }

    // --- page_credit() tests ---

    // 1. ok + tally{0,0,0} + non-ET → 0 (noopt)
    public function test_page_credit_noopt_zero_when_s0_a0(): void {
        $page  = $this->ok_page();
        $tally = [ 'safe' => 0, 'aggressive' => 0, 'needed' => 0 ];
        $this->assertSame( 0, \AIAS_Scan_Status::page_credit( $page, $tally ) );
    }

    // 2. ok + tally{0,0,5} (needed only) → 0 (noopt: S:0 A:0 regardless of needed)
    public function test_page_credit_noopt_zero_when_needed_only(): void {
        $page  = $this->ok_page();
        $tally = [ 'safe' => 0, 'aggressive' => 0, 'needed' => 5 ];
        $this->assertSame( 0, \AIAS_Scan_Status::page_credit( $page, $tally ) );
    }

    // 3. ok + tally{1,0,0} (safe>=1) → 1
    public function test_page_credit_one_when_safe_nonzero(): void {
        $page  = $this->ok_page();
        $tally = [ 'safe' => 1, 'aggressive' => 0, 'needed' => 0 ];
        $this->assertSame( 1, \AIAS_Scan_Status::page_credit( $page, $tally ) );
    }

    // 4. ok + tally{0,1,0} (aggressive>=1) → 1
    public function test_page_credit_one_when_aggressive_nonzero(): void {
        $page  = $this->ok_page();
        $tally = [ 'safe' => 0, 'aggressive' => 1, 'needed' => 0 ];
        $this->assertSame( 1, \AIAS_Scan_Status::page_credit( $page, $tally ) );
    }

    // 5. ok + tally{0,0,0} + extra_time_charged=true → 2 (ET-exempt: 1 base + 1 ET)
    public function test_page_credit_et_exempt_from_noopt_zero(): void {
        $page  = $this->ok_page( [ 'extra_time_charged' => true ] );
        $tally = [ 'safe' => 0, 'aggressive' => 0, 'needed' => 0 ];
        $this->assertSame( 2, \AIAS_Scan_Status::page_credit( $page, $tally ) );
    }

    // 6. ok + tally=null → 1 (no tally → legacy, no override) ← the R2 guard
    public function test_page_credit_null_tally_keeps_legacy_one(): void {
        $page = $this->ok_page();
        $this->assertSame( 1, \AIAS_Scan_Status::page_credit( $page, null ) );
    }

    // 7. error page → 0 (classify returns 0 credits for error with no broken_devices)
    public function test_page_credit_error_page_is_zero(): void {
        $page  = $this->error_page();
        $tally = [ 'safe' => 0, 'aggressive' => 0, 'needed' => 0 ];
        $this->assertSame( 0, \AIAS_Scan_Status::page_credit( $page, $tally ) );
    }

    // 8. origin_unavailable → 0 (skipped)
    public function test_page_credit_origin_unavailable_is_zero(): void {
        $page  = $this->skipped_page();
        $tally = [ 'safe' => 0, 'aggressive' => 0, 'needed' => 0 ];
        $this->assertSame( 0, \AIAS_Scan_Status::page_credit( $page, $tally ) );
    }

    // --- billable_credit_total() tests ---

    // 9. with by_page over [okNoopt, okWithSafe, errorPage] → 0+1+0 = 1
    public function test_billable_credit_total_with_by_page_noopt_aware(): void {
        $pages = [
            $this->ok_page(),           // noopt → 0
            $this->ok_page(),           // safe=1 → 1
            $this->error_page(),        // error → 0
        ];
        $by_page = [
            0 => [ 'safe' => 0, 'aggressive' => 0, 'needed' => 0 ],
            1 => [ 'safe' => 1, 'aggressive' => 0, 'needed' => 0 ],
            2 => [ 'safe' => 0, 'aggressive' => 0, 'needed' => 0 ],
        ];
        $this->assertSame( 1, ScannerAjax::billable_credit_total( $pages, $by_page ) );
    }

    // 10. WITHOUT by_page over [ok, ok, ok] → 3 (legacy backward-compat; the R2 contract)
    public function test_billable_credit_total_no_by_page_is_legacy(): void {
        $pages = [
            $this->ok_page(),
            $this->ok_page(),
            $this->ok_page(),
        ];
        // No second arg → legacy: each ok page = 1 credit, total = 3.
        $this->assertSame( 3, ScannerAjax::billable_credit_total( $pages ) );
    }
}

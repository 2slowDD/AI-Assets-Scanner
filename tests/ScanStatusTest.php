<?php
use PHPUnit\Framework\TestCase;

final class ScanStatusTest extends TestCase {
    private function page( array $o ): array {
        return array_merge( [ 'url' => 'https://x/', 'status' => 'done', 'assets' => [], 'broken_devices' => [] ], $o );
    }
    public function test_ok_clean_done_page(): void {
        $r = AIAS_Scan_Status::classify( $this->page([]) );
        $this->assertSame( 'ok', $r['class'] );
        $this->assertSame( 'OK', $r['label'] );
        $this->assertSame( 1, $r['credits'] );
    }
    public function test_partial_one_device_rate_fail(): void {
        $r = AIAS_Scan_Status::classify( $this->page([ 'broken_devices' => [ [ 'device' => 'mobile', 'reason' => 'tier1_http_rate_limit' ] ] ]) );
        $this->assertSame( 'partial', $r['class'] );
        $this->assertSame( 1, $r['credits'] );
        $this->assertStringContainsString( 'Mobile failed', $r['label'] );
        $this->assertStringContainsString( 'rate limit (429)', $r['label'] );
    }
    public function test_blocked_bot_protection(): void {
        $r = AIAS_Scan_Status::classify( $this->page([ 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ] ] ]) );
        $this->assertSame( 'blocked', $r['class'] );
        $this->assertStringContainsString( 'Cloudflare challenge', $r['label'] );
    }
    public function test_error_zero_credit(): void {
        $r = AIAS_Scan_Status::classify( $this->page([ 'status' => 'error', 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier1_http_5xx' ] ] ]) );
        $this->assertSame( 'error', $r['class'] );
        $this->assertSame( 0, $r['credits'] );
        $this->assertStringContainsString( '5xx', $r['label'] );
    }
    public function test_bot_block_precedence_over_error_status(): void {
        $r = AIAS_Scan_Status::classify( $this->page([ 'status' => 'error', 'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier2_imperva_challenge' ] ] ]) );
        $this->assertSame( 'blocked', $r['class'] );
    }
    public function test_is_broken_without_reason_is_not_affected(): void {
        $r = AIAS_Scan_Status::classify( $this->page([ 'broken_devices' => [ [ 'device' => 'mobile', 'is_broken' => true, 'reason' => '' ] ] ]) );
        $this->assertSame( 'ok', $r['class'] );
    }
    public function test_origin_unavailable_is_skipped_not_ok_and_zero_credits(): void {
        $out = AIAS_Scan_Status::classify( [ 'url' => 'https://x/', 'status' => 'origin_unavailable' ] );
        $this->assertSame( 'skipped', $out['class'] );
        $this->assertSame( 0, $out['credits'] );
        $this->assertStringContainsString( 'Origin unavailable', $out['label'] );
    }
    public function test_build_pages_merges_status_and_tallies_by_index(): void {
        $pages_raw = [
            [ 'url' => 'https://x/a', 'status' => 'done',  'broken_devices' => [] ],
            [ 'url' => 'https://x/b', 'status' => 'error', 'broken_devices' => [] ],
        ];
        $by_page = [ 0 => [ 'safe' => 5, 'aggressive' => 2, 'needed' => 18 ] ]; // index 1 (error) absent
        $rows = AIAS_Scan_Status::build_pages( $pages_raw, $by_page );
        $this->assertCount( 2, $rows );
        $this->assertSame( 1, $rows[0]['n'] );
        $this->assertSame( 'https://x/a', $rows[0]['url'] );
        $this->assertSame( 'ok', $rows[0]['status_class'] );
        $this->assertSame( [ 5, 2, 18 ], [ $rows[0]['safe'], $rows[0]['aggressive'], $rows[0]['needed'] ] );
        $this->assertSame( 2, $rows[1]['n'] );
        $this->assertSame( 'error', $rows[1]['status_class'] );
        $this->assertSame( 0, $rows[1]['credits'] );
        $this->assertSame( [ 0, 0, 0 ], [ $rows[1]['safe'], $rows[1]['aggressive'], $rows[1]['needed'] ] ); // error → 0 (render as —)
    }
    public function test_et_candidate_ok_with_bail_flags_true(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'done', 'broken_devices' => [], 'deadline_bail_count' => 2 ] ],
            []
        );
        $this->assertTrue( $rows[0]['et_candidate'] ); // ok + count>0 → yes (AC-ET-5)
    }
    public function test_et_candidate_ok_no_bail_is_false(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'done', 'broken_devices' => [], 'deadline_bail_count' => 0 ] ],
            []
        );
        $this->assertFalse( $rows[0]['et_candidate'] ); // ok + count 0 → — (AC-ET-10)
    }
    public function test_et_candidate_partial_excluded_even_with_bail(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'done',
                'broken_devices' => [ [ 'device' => 'mobile', 'reason' => 'tier1_http_rate_limit' ] ],
                'deadline_bail_count' => 3 ] ],
            []
        );
        $this->assertFalse( $rows[0]['et_candidate'] ); // partial → — (ok-only; AC-ET-6)
    }
    public function test_et_candidate_blocked_excluded_even_with_bail(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'done',
                'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ] ],
                'deadline_bail_count' => 5 ] ],
            []
        );
        $this->assertFalse( $rows[0]['et_candidate'] ); // blocked (WAF do-NOT) → — even with count>0 (AC-ET-8)
    }
    public function test_et_candidate_error_excluded(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'error',
                'broken_devices' => [ [ 'device' => 'desktop', 'reason' => 'tier1_http_5xx' ] ],
                'deadline_bail_count' => 4 ] ],
            []
        );
        $this->assertFalse( $rows[0]['et_candidate'] ); // error → — (AC-ET-7)
    }
    public function test_et_candidate_skipped_excluded(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'origin_unavailable' ] ],
            []
        );
        $this->assertFalse( $rows[0]['et_candidate'] ); // skipped, no field → — (AC-ET-9)
    }
    public function test_et_candidate_missing_field_is_false_backfill_safe(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'done', 'broken_devices' => [] ] ], // no deadline_bail_count
            []
        );
        $this->assertFalse( $rows[0]['et_candidate'] ); // historical scan → — (AC-ET-11), no PHP warning
    }

    // FU-AAS-ET-CREDIT-DISPLAY (2026-06-02) — per-URL Credits column must reflect the +1
    // Extra-Time charge. Railway stamps `extra_time_charged` on pages that ran a billed ET
    // continuation (1:1 with the scan-level et_ran SaaS charges); classify() adds the +1.
    public function test_extra_time_charged_done_adds_one_credit(): void {
        $r = AIAS_Scan_Status::classify( $this->page([ 'extra_time_charged' => true ]) );
        $this->assertSame( 'ok', $r['class'] );
        $this->assertSame( 2, $r['credits'] ); // base 1 + ET 1 = the amount SaaS billed
    }
    public function test_no_extra_time_charged_done_is_one_credit(): void {
        $r = AIAS_Scan_Status::classify( $this->page([ 'extra_time_charged' => false ]) );
        $this->assertSame( 1, $r['credits'] );
    }
    public function test_extra_time_charged_partial_adds_one_credit(): void {
        $r = AIAS_Scan_Status::classify( $this->page([
            'broken_devices'     => [ [ 'device' => 'mobile', 'reason' => 'tier1_http_rate_limit' ] ],
            'extra_time_charged' => true,
        ]) );
        $this->assertSame( 'partial', $r['class'] );
        $this->assertSame( 2, $r['credits'] );
    }
    public function test_extra_time_charged_blocked_adds_one_credit(): void {
        $r = AIAS_Scan_Status::classify( $this->page([
            'broken_devices'     => [ [ 'device' => 'desktop', 'reason' => 'tier2_cf_challenge' ] ],
            'extra_time_charged' => true,
        ]) );
        $this->assertSame( 'blocked', $r['class'] );
        $this->assertSame( 2, $r['credits'] ); // base 1 (done) + ET 1
    }
    public function test_extra_time_charged_error_shows_et_credit_only(): void {
        // An ET continuation that ran but finished 'error': SaaS billed the +1 (et_ran), base 0.
        $r = AIAS_Scan_Status::classify( $this->page([
            'status'             => 'error',
            'broken_devices'     => [ [ 'device' => 'desktop', 'reason' => 'tier1_http_5xx' ] ],
            'extra_time_charged' => true,
        ]) );
        $this->assertSame( 'error', $r['class'] );
        $this->assertSame( 1, $r['credits'] ); // base 0 + ET 1
    }
    public function test_origin_unavailable_ignores_extra_time_charged(): void {
        $r = AIAS_Scan_Status::classify( [ 'url' => 'https://x/', 'status' => 'origin_unavailable', 'extra_time_charged' => true ] );
        $this->assertSame( 'skipped', $r['class'] );
        $this->assertSame( 0, $r['credits'] ); // origin never runs a continuation → never ET-charged
    }
    public function test_build_pages_threads_extra_time_charged_to_credits(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ [ 'url' => 'https://x/', 'status' => 'done', 'broken_devices' => [], 'extra_time_charged' => true ] ],
            []
        );
        $this->assertSame( 2, $rows[0]['credits'] );
    }
    public function test_missing_extra_time_charged_is_backfill_safe(): void {
        // Historical scan / older Railway worker: no field → base credit only, no PHP warning.
        $r = AIAS_Scan_Status::classify( $this->page([]) );
        $this->assertSame( 1, $r['credits'] );
    }
}

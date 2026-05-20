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
}

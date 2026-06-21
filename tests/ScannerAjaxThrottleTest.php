<?php
use PHPUnit\Framework\TestCase;
use CUScanner\Admin\ScannerAjax;

final class ScannerAjaxThrottleTest extends TestCase {

    private function page( array $broken_devices ): array {
        return [ 'url' => 'https://x/', 'status' => 'ok', 'assets' => [], 'broken_devices' => $broken_devices ];
    }
    private function rl( ?string $attribution ): array {
        $e = [ 'device' => 'desktop', 'reason' => 'tier1_http_rate_limit', 'is_broken' => true ];
        if ( null !== $attribution ) { $e['attribution'] = $attribution; }
        return $e;
    }

    // --- aggregate_rate_limit_attribution ---

    public function test_most_frequent_wins(): void {
        $pages = [
            $this->page( [ $this->rl( 'cloudflare' ) ] ),
            $this->page( [ $this->rl( 'cloudflare' ) ] ),
            $this->page( [ $this->rl( 'host' ) ] ),
        ];
        $this->assertSame( 'cloudflare', ScannerAjax::aggregate_rate_limit_attribution( $pages ) );
    }

    public function test_tie_prefers_cdn_edge_over_host(): void {
        $pages = [ $this->page( [ $this->rl( 'cloudflare' ) ] ), $this->page( [ $this->rl( 'host' ) ] ) ];
        $this->assertSame( 'cloudflare', ScannerAjax::aggregate_rate_limit_attribution( $pages ) );
    }

    public function test_all_host(): void {
        $pages = [ $this->page( [ $this->rl( 'host' ) ] ), $this->page( [ $this->rl( 'host' ) ] ) ];
        $this->assertSame( 'host', ScannerAjax::aggregate_rate_limit_attribution( $pages ) );
    }

    public function test_present_unknown_is_counted(): void {
        // post-deploy genuine 'unknown' (worker classified but couldn't attribute) → key present → counts.
        $pages = [ $this->page( [ $this->rl( 'unknown' ) ] ) ];
        $this->assertSame( 'unknown', ScannerAjax::aggregate_rate_limit_attribution( $pages ) );
    }

    public function test_absent_attribution_key_yields_null_backward_safe(): void {
        // pre-worker-deploy: rate-limited entry has NO attribution key → no key collected → null → no notice (AC-A5).
        $pages = [ $this->page( [ $this->rl( null ) ] ), $this->page( [ $this->rl( null ) ] ) ];
        $this->assertNull( ScannerAjax::aggregate_rate_limit_attribution( $pages ) );
    }

    public function test_non_rate_limit_reasons_ignored(): void {
        $pages = [ $this->page( [ [ 'device' => 'desktop', 'reason' => 'tier1_zero_bytes', 'attribution' => 'cloudflare' ] ] ) ];
        $this->assertNull( ScannerAjax::aggregate_rate_limit_attribution( $pages ) );
    }

    public function test_empty_or_no_broken_devices_yields_null(): void {
        $this->assertNull( ScannerAjax::aggregate_rate_limit_attribution( [] ) );
        $this->assertNull( ScannerAjax::aggregate_rate_limit_attribution( [ [ 'url' => 'https://x/', 'status' => 'ok', 'assets' => [] ] ] ) );
    }

    // --- throttle_notice_kind ---

    public function test_kind_mapping(): void {
        $this->assertSame( 'cdn', ScannerAjax::throttle_notice_kind( 'cloudflare' ) );
        $this->assertSame( 'cdn', ScannerAjax::throttle_notice_kind( 'akamai' ) );
        $this->assertSame( 'cdn', ScannerAjax::throttle_notice_kind( 'imperva' ) );
        $this->assertSame( 'cdn', ScannerAjax::throttle_notice_kind( 'waf' ) );
        $this->assertSame( 'origin', ScannerAjax::throttle_notice_kind( 'host' ) );
        $this->assertSame( 'unknown', ScannerAjax::throttle_notice_kind( 'unknown' ) );
        $this->assertSame( 'unknown', ScannerAjax::throttle_notice_kind( 'something-else' ) );
    }

    // --- throttle_supersedes_cdn_notice ---

    public function test_supersede_only_when_cdn_and_name_matches_detected(): void {
        $this->assertTrue( ScannerAjax::throttle_supersedes_cdn_notice( 'cdn', 'cloudflare', 'cloudflare' ) );
        $this->assertFalse( ScannerAjax::throttle_supersedes_cdn_notice( 'cdn', 'cloudflare', 'fastly' ) ); // different CDN
        $this->assertFalse( ScannerAjax::throttle_supersedes_cdn_notice( 'origin', 'host', 'cloudflare' ) ); // not cdn kind
        $this->assertFalse( ScannerAjax::throttle_supersedes_cdn_notice( 'cdn', 'cloudflare', null ) );      // nothing detected
    }
}

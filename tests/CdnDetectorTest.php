<?php
use PHPUnit\Framework\TestCase;
use CUScanner\Cdn\Detector;

final class CdnDetectorTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_self_sniff_uses_5s_timeout_caches_and_returns_cloudflare(): void {
        WP_Mock::userFunction('get_transient')->with('cu_scanner_cdn_detected')->once()->andReturn(false);
        WP_Mock::userFunction('home_url')->andReturn('https://site.test/');
        WP_Mock::userFunction('wp_remote_get')
            ->once()
            ->with('https://site.test/', \Mockery::on(fn($a) => ($a['timeout'] ?? null) === 5))
            ->andReturn(['response' => ['code' => 200]]);
        WP_Mock::userFunction('is_wp_error')->andReturn(false);
        WP_Mock::userFunction('wp_remote_retrieve_headers')->andReturn(['cf-ray' => 'abc']);
        WP_Mock::userFunction('set_transient')->once()->with('cu_scanner_cdn_detected', 'cloudflare', 43200);

        $this->assertSame('cloudflare', (new Detector())->detect());
    }

    public function test_failed_fetch_is_quiet_and_returns_null(): void {
        WP_Mock::userFunction('get_transient')->andReturn(false);
        WP_Mock::userFunction('home_url')->andReturn('https://site.test/');
        WP_Mock::userFunction('wp_remote_get')->andReturn(new \WP_Error());
        WP_Mock::userFunction('is_wp_error')->andReturn(true);
        WP_Mock::userFunction('set_transient')->once()->with('cu_scanner_cdn_detected', '', 1800);
        $this->assertNull((new Detector())->detect());
    }
}

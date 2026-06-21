<?php
use PHPUnit\Framework\TestCase;
use CUScanner\Admin\AdminPages;

final class CdnNoticeTest extends TestCase {
    public function test_notice_visibility_logic(): void {
        $this->assertTrue( AdminPages::cdn_notice_should_show( 'cloudflare', '' ) );           // detected, not acked
        $this->assertFalse( AdminPages::cdn_notice_should_show( 'cloudflare', 'cloudflare' ) ); // acked same CDN
        $this->assertTrue( AdminPages::cdn_notice_should_show( 'fastly', 'cloudflare' ) );     // CDN changed — re-show
        $this->assertFalse( AdminPages::cdn_notice_should_show( null, '' ) );                  // no CDN detected
    }
}

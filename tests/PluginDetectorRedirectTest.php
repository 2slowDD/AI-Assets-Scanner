<?php
// tests/PluginDetectorRedirectTest.php
namespace CUScanner\Tests;

use CUScanner\Scanner\PluginDetector;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * AC-RC-2 / AC-RC-7 — same_site() fail-closed guard for redirect resolution.
 *
 * Covers: host equality, www-variant matching, multi-part TLD rejection,
 * non-www subdomain rejection, and cross-domain rejection.
 */
class PluginDetectorRedirectTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /** Stub wp_parse_url as a passthrough to native parse_url (component-form aware). */
    private function stub_wp_parse_url(): void {
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
            fn( $url, $component = -1 ) => parse_url( $url, $component )
        );
    }

    // -------------------------------------------------------------------------
    // AC-RC-2 — same host is accepted
    // -------------------------------------------------------------------------

    public function test_same_site_host_equality(): void {
        $this->stub_wp_parse_url();
        $this->assertTrue(
            PluginDetector::__test_same_site( 'https://cloudways.com/', 'https://cloudways.com/en' )
        );
    }

    // -------------------------------------------------------------------------
    // AC-RC-7 — www. variant is accepted (both directions)
    // -------------------------------------------------------------------------

    public function test_same_site_www_variant(): void {
        $this->stub_wp_parse_url();
        $this->assertTrue(
            PluginDetector::__test_same_site( 'https://cloudways.com', 'https://www.cloudways.com/en' )
        );
        $this->assertTrue(
            PluginDetector::__test_same_site( 'https://www.example.com', 'https://example.com/x' )
        );
    }

    // -------------------------------------------------------------------------
    // Fail-closed cases — must return false
    // -------------------------------------------------------------------------

    public function test_same_site_rejects_multipart_tld(): void {
        $this->stub_wp_parse_url();
        // foo.co.uk vs bar.co.uk share a TLD but are different registrable domains;
        // no eTLD+1 logic — fail closed.
        $this->assertFalse(
            PluginDetector::__test_same_site( 'https://foo.co.uk', 'https://bar.co.uk' )
        );
    }

    public function test_same_site_rejects_nonwww_subdomain(): void {
        $this->stub_wp_parse_url();
        $this->assertFalse(
            PluginDetector::__test_same_site( 'https://example.com', 'https://m.example.com' )
        );
    }

    public function test_same_site_rejects_cross_domain(): void {
        $this->stub_wp_parse_url();
        $this->assertFalse(
            PluginDetector::__test_same_site( 'https://example.com', 'https://partner.com' )
        );
    }
}

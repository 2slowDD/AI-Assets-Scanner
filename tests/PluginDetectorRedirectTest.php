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

    // -------------------------------------------------------------------------
    // AC-RC-5 — extract_final_url reads url from http_response object
    // -------------------------------------------------------------------------

    public function test_extract_final_url_reads_response_object(): void {
        $obj = new class { public $url = 'https://www.cloudways.com/en'; };
        $resp = [ 'http_response' => new class( $obj ) {
            public function __construct( private $o ) {}
            public function get_response_object() { return $this->o; }
        } ];
        $this->assertSame( 'https://www.cloudways.com/en', PluginDetector::__test_extract_final_url( $resp ) );
    }

    // -------------------------------------------------------------------------
    // AC-RC-6 — extract_final_url returns null on malformed input
    // -------------------------------------------------------------------------

    public function test_extract_final_url_null_on_malformed(): void {
        $this->assertNull( PluginDetector::__test_extract_final_url( [] ) );
        $this->assertNull( PluginDetector::__test_extract_final_url( [ 'http_response' => null ] ) );
    }

    // -------------------------------------------------------------------------
    // AC-RC-5 — extract_canonical absolutizes a relative href
    // -------------------------------------------------------------------------

    public function test_extract_canonical_absolutizes(): void {
        $this->stub_wp_parse_url();
        $body = '<link rel="canonical" href="/en/" />';
        $this->assertSame(
            'https://www.cloudways.com/en/',
            PluginDetector::__test_extract_canonical( $body, 'https://www.cloudways.com/' )
        );
    }

    // -------------------------------------------------------------------------
    // AC-RC-5 — extract_canonical returns null when no canonical tag present
    // -------------------------------------------------------------------------

    public function test_extract_canonical_null_when_absent(): void {
        $this->assertNull( PluginDetector::__test_extract_canonical( '<p>no canonical</p>', 'https://x.com/' ) );
    }
}

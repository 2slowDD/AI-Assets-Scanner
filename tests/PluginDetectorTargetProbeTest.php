<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\PluginDetector;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * FU-NEW-2 Phase 2 — target probe behavior tests.
 * Spec §5.3 + §5.4 + §6.1.1.
 */
class PluginDetectorTargetProbeTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * Header matcher: case-insensitive substring on header value.
     * AC-N2-1 source: 'header' detection path.
     */
    public function test_header_matcher_case_insensitive_substring() {
        $headers = [ 'X-LiteSpeed-Cache' => 'hit' ];
        $patterns = [ 'x-litespeed-cache' ];
        $this->assertTrue( PluginDetector::__test_header_match( $headers, $patterns ) );

        $patterns = [ 'X-NotPresent' ];
        $this->assertFalse( PluginDetector::__test_header_match( $headers, $patterns ) );
    }

    /**
     * Body matcher: case-insensitive substring on body string.
     * AC-N2-1 source: 'body' detection path (Breeze comment is the canonical case).
     */
    public function test_body_matcher_case_insensitive_substring() {
        $body = '<!-- Cache served by breeze CACHE (Desktop) - Last modified: Fri, 15 May 2026 -->';
        $patterns = [ 'Cache served by breeze' ];
        $this->assertTrue( PluginDetector::__test_body_match( $body, $patterns ) );

        $patterns = [ 'cache served by BREEZE' ];  // case-insensitive
        $this->assertTrue( PluginDetector::__test_body_match( $body, $patterns ) );

        $patterns = [ 'not in body' ];
        $this->assertFalse( PluginDetector::__test_body_match( $body, $patterns ) );
    }

    /**
     * Body matcher: empty patterns array never matches.
     */
    public function test_body_matcher_empty_patterns_returns_false() {
        $this->assertFalse( PluginDetector::__test_body_match( 'any body', [] ) );
    }

    /**
     * Body matcher: 32KB body length cap (CPU-bound; per §5.5 + §8 row 18).
     */
    public function test_body_matcher_respects_32kb_cap() {
        $padding = str_repeat( 'x', 40000 );
        $body = $padding . 'Cache served by breeze' . $padding;
        // Marker is at offset 40000, beyond 32KB cap — must NOT match.
        $this->assertFalse( PluginDetector::__test_body_match( $body, [ 'Cache served by breeze' ] ) );
    }
}

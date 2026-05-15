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

    /**
     * AC-N2 — outcome classifier follows §5.4 decision tree.
     * Precedence: probe_failed > non_wordpress > optimizer classification.
     */
    public function test_classifier_probe_failed_takes_precedence() {
        // Both probes failed → probe_failed regardless of any detected entries.
        $r = PluginDetector::__test_classify_outcome(
            true,           // probe_failed flag
            true,           // is_wordpress (irrelevant under probe_failed)
            [ ['class' => 'A'] ]  // detected (irrelevant under probe_failed)
        );
        $this->assertSame( 'probe_failed', $r );
    }

    public function test_classifier_non_wordpress_when_no_wp_signals() {
        // No WP signals → non_wordpress regardless of body marker matches (§5.4 trust-WP-first rule).
        $r = PluginDetector::__test_classify_outcome(
            false,          // probe_failed
            false,          // is_wordpress = false
            [ ['class' => 'B'] ]  // body marker may have matched but no WP context
        );
        $this->assertSame( 'non_wordpress', $r );
    }

    public function test_classifier_class_a_clean_when_only_a_detected() {
        $r = PluginDetector::__test_classify_outcome( false, true, [ ['class' => 'A'] ] );
        $this->assertSame( 'class_a_clean', $r );
    }

    public function test_classifier_class_a_star_treated_as_a_for_classification() {
        $r = PluginDetector::__test_classify_outcome( false, true, [ ['class' => 'A_star'] ] );
        $this->assertSame( 'class_a_clean', $r );
    }

    public function test_classifier_class_bc_only_when_no_a() {
        $r = PluginDetector::__test_classify_outcome( false, true, [ ['class' => 'B'] ] );
        $this->assertSame( 'class_bc_only', $r );
        $r = PluginDetector::__test_classify_outcome( false, true, [ ['class' => 'C'] ] );
        $this->assertSame( 'class_bc_only', $r );
    }

    public function test_classifier_hybrid_when_a_plus_bc() {
        $r = PluginDetector::__test_classify_outcome( false, true, [
            ['class' => 'A'], ['class' => 'B']
        ] );
        $this->assertSame( 'hybrid_a_plus_bc', $r );
        $r = PluginDetector::__test_classify_outcome( false, true, [
            ['class' => 'A_star'], ['class' => 'C']
        ] );
        $this->assertSame( 'hybrid_a_plus_bc', $r );
    }

    public function test_classifier_no_clue_when_wp_but_no_optimizer() {
        $r = PluginDetector::__test_classify_outcome( false, true, [] );
        $this->assertSame( 'no_clue', $r );
    }
}

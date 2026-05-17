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

    protected $http_stub_response = null;
    protected $http_stub_calls    = [];

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

    // -----------------------------------------------------------------
    // Minor #6 absorption — extra matcher coverage (header symmetry +
    // array-valued header flattening).
    // -----------------------------------------------------------------

    /**
     * Header matcher: empty patterns array never matches (symmetry with body_match).
     */
    public function test_header_matcher_empty_patterns_returns_false() {
        $this->assertFalse( PluginDetector::__test_header_match( [ 'X-Some-Header' => 'value' ], [] ) );
    }

    /**
     * Header matcher: array-valued headers (e.g. multi-value Set-Cookie) flattened via implode.
     */
    public function test_header_matcher_handles_array_valued_headers() {
        $headers = [ 'Set-Cookie' => [ '_lscache_vary=abc', 'wordpress_logged_in=def' ] ];
        $this->assertTrue( PluginDetector::__test_header_match( $headers, [ '_lscache_vary' ] ) );
    }

    // -----------------------------------------------------------------
    // Task 2.5 — probe_target_stack() integration tests.
    // -----------------------------------------------------------------

    /**
     * AC-N2-SSRF (i) — scheme allowlist rejects file://.
     */
    public function test_probe_rejects_file_scheme() {
        // wp_remote_get must NOT be called for invalid scheme.
        WP_Mock::userFunction( 'wp_remote_get' )->never();
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );

        $r = PluginDetector::probe_target_stack( 'file:///etc/passwd', null, 12 );
        $this->assertSame( 'probe_failed', $r['outcome'] );
        $this->assertSame( 'invalid_scheme', $r['reason'] );
    }

    /**
     * AC-N2-SSRF (i) — scheme allowlist rejects javascript:.
     */
    public function test_probe_rejects_javascript_scheme() {
        WP_Mock::userFunction( 'wp_remote_get' )->never();
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );

        $r = PluginDetector::probe_target_stack( 'javascript:alert(1)', null, 12 );
        $this->assertSame( 'probe_failed', $r['outcome'] );
        $this->assertSame( 'invalid_scheme', $r['reason'] );
    }

    /**
     * is_wordpress detection via meta generator (spec §5.1 WordPress core row).
     */
    public function test_probe_detects_wordpress_via_meta_generator() {
        $this->stub_probe_response( 200, [], '<meta name="generator" content="WordPress 6.4">' );
        $r = PluginDetector::probe_target_stack( 'https://example.com/', null, 12 );
        $this->assertTrue( $r['is_wordpress'] );
    }

    /**
     * is_wordpress false when no WP signals (non-WP target — spec §5.4 step 3).
     */
    public function test_probe_is_wordpress_false_when_no_wp_signals() {
        $this->stub_probe_response( 200, [], '<html><body>Generic non-WP site</body></html>' );
        $r = PluginDetector::probe_target_stack( 'https://static-html.example.com/', null, 12 );
        $this->assertSame( 'non_wordpress', $r['outcome'] );
        $this->assertFalse( $r['is_wordpress'] );
    }

    /**
     * AC-N2-1 — Breeze body marker detected → class_bc_only outcome → empty bypass_suffixes.
     */
    public function test_probe_detects_breeze_class_bc_only() {
        $this->stub_probe_response( 200, [],
            '<meta name="generator" content="WordPress 6.4"><!-- Cache served by breeze CACHE (Desktop) -->' );
        $r = PluginDetector::probe_target_stack( 'https://pinadventures.com/', null, 12 );
        $this->assertSame( 'class_bc_only', $r['outcome'] );
        $this->assertTrue( $r['is_wordpress'] );
        $this->assertCount( 1, $r['detected'] );
        $this->assertSame( 'Breeze', $r['detected'][0]['name'] );
        $this->assertSame( 'body',   $r['detected'][0]['source'] );
        $this->assertSame( [], $r['bypass_suffixes'] );
    }

    /**
     * AC-N2-7 — LiteSpeed header + WP Fastest Cache body → hybrid.
     */
    public function test_probe_detects_hybrid_a_plus_bc() {
        $this->stub_probe_response( 200,
            [ 'x-litespeed-cache' => 'hit' ],
            '<meta name="generator" content="WordPress 6.4"><!-- WP Fastest Cache file was created in -->'
        );
        $r = PluginDetector::probe_target_stack( 'https://hybrid.example.com/', null, 12 );
        $this->assertSame( 'hybrid_a_plus_bc', $r['outcome'] );
        $this->assertSame( [ 'LSCWP_CTRL=before_optm' ], $r['bypass_suffixes'] );
    }

    /**
     * AC-N2-9-unit (probe-side) — FlyingPress body marker → class_a_clean → 'no_optimize' suffix.
     */
    public function test_probe_detects_flying_press_emits_no_optimize() {
        $this->stub_probe_response( 200, [],
            '<meta name="generator" content="WordPress 6.4"><!-- Optimized by FlyingPress -->' );
        $r = PluginDetector::probe_target_stack( 'https://flyingpress-site.example.com/', null, 12 );
        $this->assertSame( 'class_a_clean', $r['outcome'] );
        $this->assertSame( [ 'no_optimize' ], $r['bypass_suffixes'] );
    }

    /**
     * AC-N2-5 — HTTP 403 → probe_failed.
     */
    public function test_probe_http_403_returns_probe_failed() {
        $this->stub_probe_response( 403, [], 'Forbidden' );
        $r = PluginDetector::probe_target_stack( 'https://bot-protected.example.com/', null, 12 );
        $this->assertSame( 'probe_failed', $r['outcome'] );
        $this->assertStringContainsString( '403', $r['reason'] );
    }

    /**
     * AC-N2-5 — WP_Error → probe_failed with sanitized reason.
     */
    public function test_probe_wp_error_returns_probe_failed_sanitized() {
        $err = $this->make_wp_error( 'http_request_failed', 'Connection refused to 10.0.0.5:8080 in /home/dev/path' );
        $this->stub_probe_wp_error( $err );
        $r = PluginDetector::probe_target_stack( 'https://unreachable.example.com/', null, 12 );
        $this->assertSame( 'probe_failed', $r['outcome'] );
        // §6.1.1 sanitization — IPs redacted, internal paths redacted, length-capped.
        $this->assertStringNotContainsString( '10.0.0.5', $r['reason'] );
        $this->assertStringNotContainsString( '/home/',   $r['reason'] );
        $this->assertLessThanOrEqual( 120, strlen( $r['reason'] ) );
    }

    /**
     * AC-N2-4 — 24h transient cache hit returns cache_hit=true and skips wp_remote_get.
     */
    public function test_probe_cache_hit_returns_cached_result() {
        $cached = [
            'outcome'         => 'class_a_clean',
            'detected'        => [ [ 'name' => 'WP Rocket', 'class' => 'A', 'bypass_query' => 'nowprocket', 'source' => 'body' ] ],
            'bypass_suffixes' => [ 'nowprocket' ],
            'is_wordpress'    => true,
        ];
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( $cached );
        WP_Mock::userFunction( 'wp_remote_get' )->never();

        $r = PluginDetector::probe_target_stack( 'https://cached.example.com/', null, 12 );
        $this->assertTrue( $r['cache_hit'] );
        $this->assertSame( 'class_a_clean', $r['outcome'] );
    }

    /**
     * AC-N2-12 — non-WP returns empty bypass_suffixes (no host stack leak).
     */
    public function test_probe_non_wordpress_returns_empty_bypass_suffixes() {
        $this->stub_probe_response( 200, [], '<html>not WP</html>' );
        $r = PluginDetector::probe_target_stack( 'https://example.com/', null, 12 );
        $this->assertSame( [], $r['bypass_suffixes'] );
    }

    // -----------------------------------------------------------------
    // Probe-test helpers.
    // -----------------------------------------------------------------

    /**
     * Stub a successful wp_remote_get response with the given status, headers, body.
     * Mocks wp_remote_get + wp_parse_url + wp_remote_retrieve_* + get_transient + set_transient
     * + is_wp_error for the common probe path.
     */
    private function stub_probe_response( int $status, array $headers, string $body ): void {
        $response = [ 'response' => [ 'code' => $status ], 'headers' => $headers, 'body' => $body ];
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );
        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $response );
        WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( function ( $r ) { return false; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( $status );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturn( $headers );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
    }

    private function stub_probe_wp_error( $wp_error ): void {
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );
        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $wp_error );
        WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( function ( $r ) use ( $wp_error ) {
            return $r === $wp_error;
        } );
    }

    /**
     * T-N7-A (helper test for body_match use_range semantics — updated for T3 widening).
     * Verifies that body_match with $use_range=true (Pass 1) scans only first 32KB head,
     * and that $use_range=false (Pass 2, T3 widening) scans the FULL body, catching markers
     * at any byte offset (including end-of-body cache markers that were previously reachable
     * only via the former 8KB-tail window).
     */
    public function test_t_n7_helper_body_match_use_range_semantics() {
        // 95KB of filler body with a Breeze marker at byte 95000 (well beyond 32KB head).
        $filler_head = str_repeat( 'x', 50000 );      // 50KB of x's
        $filler_mid  = str_repeat( 'y', 45000 );      // 45KB of y's (so total 95KB before marker)
        $marker_tail = '<!-- Cache served by breeze -->';
        $body        = $filler_head . $filler_mid . $marker_tail;

        // Confirm test fixture: marker is BEYOND first 32KB.
        $this->assertGreaterThan( 32768, strpos( $body, 'Cache served by breeze' ) );

        // Pass 1 (use_range=true, default): marker is beyond 32KB head → MISS.
        $this->assertFalse(
            PluginDetector::__test_body_match( $body, [ 'Cache served by breeze' ] ),
            'body_match Pass 1 (use_range=true, default) should miss the marker beyond 32KB head'
        );

        // Pass 2 (use_range=false, T3 widening): full body scan → HIT anywhere in body.
        $this->assertTrue(
            PluginDetector::__test_body_match( $body, [ 'Cache served by breeze' ], false ),
            'body_match Pass 2 (use_range=false) should detect the marker via full-body scan'
        );
    }

    // -----------------------------------------------------------------
    // AAS 1.4.0 Task 7 — body_match T3 widening (Pass 2 full-body scan).
    // Spec §6.3. AC-T3-1 and AC-T3-2.
    // -----------------------------------------------------------------

    /**
     * AC-T3-2 — Pass 1 (use_range=true) still caps at 32KB head; marker beyond byte 32768 is NOT matched.
     */
    public function test_body_match_use_range_true_caps_at_32kb_head(): void {
        // Marker placed at byte 40000 — beyond the 32KB head cap.
        $body = str_repeat( 'X', 40000 ) . 'Cache served by breeze';
        $r = PluginDetector::__test_body_match( $body, [ 'Cache served by breeze' ], true );
        $this->assertFalse( $r, 'marker past byte 32768 must NOT be matched in Pass 1 (use_range=true)' );
    }

    /**
     * AC-T3-1 — Pass 2 (use_range=false) scans the full body up to the 2MB limit_response_size cap.
     * Marker at byte 100000 (beyond former 8KB-tail window) must now be found.
     */
    public function test_body_match_use_range_false_scans_full_body_t3(): void {
        // Marker placed at byte 100000 — beyond both 32KB head and former 8KB tail window.
        $body = str_repeat( 'X', 100000 ) . 'Cache served by breeze';
        $r = PluginDetector::__test_body_match( $body, [ 'Cache served by breeze' ], false );
        $this->assertTrue( $r, 'marker beyond former 8KB-tail window must be matched in Pass 2 (use_range=false)' );
    }

    /**
     * AC-T3 helper — Pass 1 (use_range=true) finds a marker within the first 32KB head.
     */
    public function test_body_match_use_range_true_finds_marker_in_first_32kb(): void {
        $body = str_repeat( 'X', 100 ) . 'Cache served by breeze' . str_repeat( 'Y', 1000 );
        $r = PluginDetector::__test_body_match( $body, [ 'Cache served by breeze' ], true );
        $this->assertTrue( $r );
    }

    /**
     * T-N7-B (helper test for single_probe_attempt parameter threading).
     * Verifies that single_probe_attempt accepts $use_range and threads it through
     * to wp_remote_get args (Range header / limit_response_size) and body_match.
     */
    public function test_t_n7_helper_single_probe_attempt_params_threaded() {
        $body = '<!doctype html><html><body>OK</body></html>';

        // Stubs required by single_probe_attempt (scheme validation + response processing).
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( function ( $r ) { return false; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturn( [] );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );

        // ----------------------------------------------------------------
        // Case 1: $use_range=true → Range header present, NO limit_response_size.
        // ----------------------------------------------------------------
        $captured_args = null;
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
            function ( $url, $args ) use ( &$captured_args, $body ) {
                $captured_args = $args;
                return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => $body ];
            }
        );

        PluginDetector::__test_single_probe_attempt( 'https://example.com/', 12, true );

        $this->assertArrayHasKey( 'Range', $captured_args['headers'],
            'Case 1: Range header must be present when $use_range=true' );
        $this->assertSame( 'bytes=0-32767', $captured_args['headers']['Range'],
            'Case 1: Range value must be bytes=0-32767' );
        $this->assertArrayNotHasKey( 'limit_response_size', $captured_args,
            'Case 1: limit_response_size must NOT be set when $use_range=true' );

        // ----------------------------------------------------------------
        // Case 2: $use_range=false → NO Range header AND limit_response_size set to 2MB.
        // ----------------------------------------------------------------
        $captured_args = null;
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
            function ( $url, $args ) use ( &$captured_args, $body ) {
                $captured_args = $args;
                return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => $body ];
            }
        );

        PluginDetector::__test_single_probe_attempt( 'https://example.com/', 12, false );

        $this->assertArrayNotHasKey( 'Range', $captured_args['headers'],
            'Case 2: Range header must NOT be present when $use_range=false' );
        $this->assertArrayHasKey( 'limit_response_size', $captured_args,
            'Case 2: limit_response_size must be set when $use_range=false' );
        $this->assertSame( 2 * 1024 * 1024, $captured_args['limit_response_size'],
            'Case 2: limit_response_size must be exactly 2MB' );
    }

    // -----------------------------------------------------------------
    // FU-NEW-7 Task 3 — two-pass orchestration integration test.
    // -----------------------------------------------------------------

    /**
     * T-N7-2: Pass 1 inconclusive both URLs; Pass 2 detects Breeze marker
     * in last 8KB of full body on URL1.
     *
     * Reproduces the pinadventures.com Breeze-on-Kinsta failure pattern from
     * FU-NEW-4/5 AC validation 2026-05-16. The Breeze HTML comment marker
     * '<!-- Cache served by breeze -->' lives at end-of-body, beyond the 32KB
     * Range cap. Pass 2's full-body fetch + tail-scan recovers it.
     */
    public function test_t_n7_2_pass_2_detects_breeze_in_tail() {
        $call_count     = 0;
        $captured_calls = [];

        // wp_remote_get returns different bodies per call:
        //   call 1 (URL1 ranged): healthy WP page, no Breeze marker, no header.
        //   call 2 (URL2 ranged): same — both Pass 1 attempts inconclusive.
        //   call 3 (URL1 full):   full body with end-of-body Breeze marker.
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
            function ( $url, $args ) use ( &$call_count, &$captured_calls ) {
                $call_count++;
                $captured_calls[] = [
                    'url'                     => $url,
                    'range'                   => $args['headers']['Range'] ?? null,
                    'has_limit_response_size' => array_key_exists( 'limit_response_size', $args ),
                ];

                if ( $call_count <= 2 ) {
                    // Pass 1 (ranged, 2 URLs): healthy WP page, no markers/header.
                    return [
                        'response' => [ 'code' => 200 ],
                        'headers'  => [], // no x-cache-handler — Kinsta-stripped
                        'body'     => '<!doctype html><html><head>'
                                    . '<meta name="generator" content="WordPress 6.4">'
                                    . str_repeat( '<meta name="x" content="y">', 1000 ) // long head, no marker
                                    . '</head><body>plain content</body></html>',
                    ];
                }
                // Pass 2 (URL1, no Range): full body with end-of-body Breeze marker.
                return [
                    'response' => [ 'code' => 200 ],
                    'headers'  => [],
                    'body'     => '<!doctype html><html><head>'
                                . '<meta name="generator" content="WordPress 6.4">'
                                . '</head><body>'
                                . str_repeat( 'content ', 5000 ) // ~40KB of body content
                                . '</body></html>'
                                . '<!-- Cache served by breeze -->',
                ];
            }
        );

        // Stub other WP functions probe_target_stack needs.
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) {
                return $parts;
            }
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( function ( $r ) { return false; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( function ( $r ) { return $r['response']['code']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturnUsing( function ( $r ) { return $r['headers']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( function ( $r ) { return $r['body']; } );
        // Ensure no 24h transient hit (fresh test).
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );

        $result = PluginDetector::probe_target_stack(
            'https://pinadventures.example/',
            'https://pinadventures.example/page-2/',
            12
        );

        // Final outcome: Breeze detected via tail-scan.
        $this->assertSame( 'class_bc_only', $result['outcome'] );
        $detected_names = array_column( $result['detected'], 'name' );
        $this->assertContains( 'Breeze', $detected_names );

        // Verify orchestration: 3 fetches (URL1 ranged, URL2 ranged, URL1 full).
        // URL2 full-body fetch should NOT fire because URL1 Pass 2 already succeeded.
        $this->assertSame( 3, $call_count );
        $this->assertSame( 'bytes=0-32767', $captured_calls[0]['range'] );  // Pass 1a
        $this->assertSame( 'bytes=0-32767', $captured_calls[1]['range'] );  // Pass 1b
        $this->assertNull( $captured_calls[2]['range'] );                    // Pass 2a (no Range)
        $this->assertTrue( $captured_calls[2]['has_limit_response_size'] );  // Pass 2a has 2MB cap
    }

    // -----------------------------------------------------------------
    // FU-NEW-7 Tasks 4–6 — two-pass orchestration branch coverage.
    // -----------------------------------------------------------------

    /**
     * T-N7-1: Pass 1 detects via response header on URL1 → Pass 2 NOT triggered.
     * Fast path: header detection on URL1 is definitive → only 1 wp_remote_get call.
     */
    public function test_t_n7_1_pass_1_header_detect_no_pass_2() {
        $call_count = 0;
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
            function ( $url, $args ) use ( &$call_count ) {
                $call_count++;
                return [
                    'response' => [ 'code' => 200 ],
                    'headers'  => [ 'x-wp-rocket-cache' => 'HIT' ],
                    'body'     => '<!doctype html><html><head><meta name="generator" content="WordPress 6.4"></head><body>OK</body></html>',
                ];
            }
        );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );
        WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( function ( $r ) { return false; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( function ( $r ) { return $r['response']['code']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturnUsing( function ( $r ) { return $r['headers']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( function ( $r ) { return $r['body']; } );

        $result = PluginDetector::probe_target_stack(
            'https://wprocket-site.example/',
            'https://wprocket-site.example/page-2/',
            12
        );

        $this->assertSame( 'class_a_clean', $result['outcome'] );
        $detected_names = array_column( $result['detected'] ?? [], 'name' );
        $this->assertContains( 'WP Rocket', $detected_names );
        $this->assertSame( 1, $call_count, 'Only URL1 should be probed (definitive on Pass 1)' );
    }

    /**
     * T-N7-3: All 4 attempts inconclusive (URL1 ranged, URL2 ranged, URL1 full,
     * URL2 full) → final outcome resolves to 'no_clue'. Worst-case path:
     * 4 fetches, ranged × 2 + full-body × 2.
     */
    public function test_t_n7_3_all_inconclusive_final_no_clue() {
        $call_count     = 0;
        $captured_calls = [];
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
            function ( $url, $args ) use ( &$call_count, &$captured_calls ) {
                $call_count++;
                $captured_calls[] = [ 'range' => $args['headers']['Range'] ?? null ];
                return [
                    'response' => [ 'code' => 200 ],
                    'headers'  => [],
                    // Healthy WP page (meta generator) but no cache plugin markers anywhere.
                    'body'     => '<!doctype html><html><head><meta name="generator" content="WordPress 6.4"></head>'
                                . '<body>' . str_repeat( 'plain content ', 5000 ) . '</body></html>',
                ];
            }
        );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );
        WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( function ( $r ) { return false; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( function ( $r ) { return $r['response']['code']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturnUsing( function ( $r ) { return $r['headers']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( function ( $r ) { return $r['body']; } );

        $result = PluginDetector::probe_target_stack(
            'https://noplugins.example/',
            'https://noplugins.example/page-2/',
            12
        );

        $this->assertSame( 'no_clue', $result['outcome'] );
        $this->assertSame( 4, $call_count, 'All 4 attempts should fire on worst-case path' );
        $this->assertSame( 'bytes=0-32767', $captured_calls[0]['range'] );
        $this->assertSame( 'bytes=0-32767', $captured_calls[1]['range'] );
        $this->assertNull( $captured_calls[2]['range'] );
        $this->assertNull( $captured_calls[3]['range'] );
    }

    /**
     * T-N7-4: Pass 1 returns inconclusive with reason='HTTP 4xx' on URL1 → Pass 2
     * NOT triggered for that URL. Fallback URL2 probed on Pass 1 and detects WP Rocket.
     * reason !== null gate at line 592 prevents Pass 2 for the 4xx URL.
     */
    public function test_t_n7_4_http_4xx_excludes_pass_2() {
        $call_count = 0;
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
            function ( $url, $args ) use ( &$call_count ) {
                $call_count++;
                if ( $call_count === 1 ) {
                    // URL1 ranged: 404 → single_probe_attempt sets reason='HTTP 404', outcome='inconclusive'.
                    return [
                        'response' => [ 'code' => 404 ],
                        'headers'  => [],
                        'body'     => 'Not Found',
                    ];
                }
                // URL2 ranged: WP Rocket header → definitive class_a_clean.
                return [
                    'response' => [ 'code' => 200 ],
                    'headers'  => [ 'x-wp-rocket-cache' => 'HIT' ],
                    'body'     => '<!doctype html><html><head><meta name="generator" content="WordPress 6.4"></head><body>OK</body></html>',
                ];
            }
        );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );
        WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( function ( $r ) { return false; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( function ( $r ) { return $r['response']['code']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturnUsing( function ( $r ) { return $r['headers']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( function ( $r ) { return $r['body']; } );

        $result = PluginDetector::probe_target_stack(
            'https://url1.example/',
            'https://url2.example/',
            12
        );

        $this->assertSame( 'class_a_clean', $result['outcome'] );
        // URL1 ranged (404) → URL2 ranged (detect WP Rocket). Pass 2 NOT triggered
        // because URL2 result is definitive (not inconclusive).
        $this->assertSame( 2, $call_count );
    }

    /**
     * T-N7-5: Pass 1 returns definitive 'non_wordpress' on URL1 → Pass 2 NOT
     * triggered. Definitive outcomes (class_*, non_wordpress) short-circuit.
     *
     * Documented trade-off in spec §3.4: WP sites with <meta generator> beyond
     * byte 32768 ship as non_wordpress and are not re-probed in v1.
     */
    public function test_t_n7_5_non_wordpress_excludes_pass_2() {
        $call_count = 0;
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
            function ( $url, $args ) use ( &$call_count ) {
                $call_count++;
                return [
                    'response' => [ 'code' => 200 ],
                    'headers'  => [],
                    // Plain non-WP page: no <meta generator>, no wp-content/, no markers.
                    'body'     => '<!doctype html><html><head><title>Not WP</title></head><body><h1>Custom site</h1></body></html>',
                ];
            }
        );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );
        WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( function ( $r ) { return false; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( function ( $r ) { return $r['response']['code']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturnUsing( function ( $r ) { return $r['headers']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( function ( $r ) { return $r['body']; } );

        $result = PluginDetector::probe_target_stack(
            'https://non-wp.example/',
            'https://non-wp.example/page-2/',
            12
        );

        $this->assertSame( 'non_wordpress', $result['outcome'] );
        $this->assertSame( 1, $call_count, 'Definitive non_wordpress on URL1 skips URL2 and Pass 2' );
    }

    /**
     * T-N7-6: False-positive control. Article body mentions 'WP Rocket' verbatim
     * in the MIDDLE of the body (beyond Pass 1's 32KB head-scan, within Pass 2's
     * full-body range but OUTSIDE the last 8KB tail-scan window). Pass 2's
     * tail-only scan correctly EXCLUDES the article body text → outcome no_clue.
     *
     * Fixture design: ~100KB body with 'WP Rocket' at byte ~90000; last 8KB
     * contains only repeated filler — no cache markers.
     */
    public function test_t_n7_6_fp_control_article_body_mentions_marker() {
        $call_count = 0;

        // Article body with WP Rocket mention in MIDDLE, not in tail 8KB.
        // ~90KB before the mention, then ~10KB of repeated filler after it.
        // The tail 8KB is purely the last portion of that filler — no cache markers.
        $article_full = '<!doctype html><html><head><meta name="generator" content="WordPress 6.4"></head><body>'
                      . str_repeat( 'Article intro content. ', 200 )     // ~4.6KB
                      . str_repeat( 'More article body filler text. ', 3000 ) // ~93KB
                      . 'Some article discussing WP Rocket and its features. '
                      . str_repeat( 'More article body content here. ', 500 ) // ~16KB trailing filler
                      . '</body></html>';
        // Confirm fixture: WP Rocket mention is NOT in the last 8KB.
        $tail_8kb = substr( $article_full, -8192 );
        $this->assertStringNotContainsString( 'WP Rocket', $tail_8kb,
            'Fixture sanity: WP Rocket must NOT appear in last 8KB of the article body' );

        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
            function ( $url, $args ) use ( &$call_count, $article_full ) {
                $call_count++;
                if ( $call_count <= 2 ) {
                    // Pass 1 (ranged): head area only — no markers, no WP Rocket header.
                    return [
                        'response' => [ 'code' => 200 ],
                        'headers'  => [],
                        'body'     => '<!doctype html><html><head><meta name="generator" content="WordPress 6.4"></head><body>',
                    ];
                }
                // Pass 2 (full body): full article with WP Rocket mention in middle, not in tail.
                return [
                    'response' => [ 'code' => 200 ],
                    'headers'  => [],
                    'body'     => $article_full,
                ];
            }
        );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );
        WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( function ( $r ) { return false; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( function ( $r ) { return $r['response']['code']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturnUsing( function ( $r ) { return $r['headers']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( function ( $r ) { return $r['body']; } );

        $result = PluginDetector::probe_target_stack(
            'https://blog.example/',
            'https://blog.example/page-2/',
            12
        );

        $this->assertSame( 'no_clue', $result['outcome'] );
        $detected_names = array_column( $result['detected'] ?? [], 'name' );
        $this->assertNotContains( 'WP Rocket', $detected_names,
            'Tail-scan must not detect WP Rocket mentioned in middle of article body' );
        $this->assertSame( 4, $call_count, 'All 4 attempts fire; tail-scan misses article body FP' );
    }

    /**
     * T-N7-7: SSRF gate fires BEFORE any wp_remote_get call.
     * URL with disallowed scheme (file://) → probe_failed immediately.
     * The SSRF check is inside single_probe_attempt; wp_remote_get is never reached.
     */
    public function test_t_n7_7_ssrf_gate_blocks_before_pass_1() {
        WP_Mock::userFunction( 'wp_remote_get' )->never();
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );

        $result = PluginDetector::probe_target_stack(
            'file:///etc/passwd',
            null,
            12
        );

        $this->assertSame( 'probe_failed', $result['outcome'] );
        $this->assertSame( 'invalid_scheme', $result['reason'] );
    }

    /**
     * T-N7-8: 24h transient cache hit on a Pass-2-resolved host short-circuits
     * all 4 attempts on the second call. Validates that cache integration works
     * correctly when the cached outcome was derived via Pass 2 (full-body tail-scan).
     *
     * First call: Pass 1 inconclusive × 2, Pass 2a detects Breeze in tail → 3 fetches.
     * Second call: transient hit → 0 fetches, same result returned.
     */
    public function test_t_n7_8_cache_hit_short_circuits_pass_2_resolved() {
        $cached_value = false;
        WP_Mock::userFunction( 'get_transient' )->andReturnUsing(
            function () use ( &$cached_value ) {
                return $cached_value;
            }
        );
        WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
            function ( $key, $value, $ttl ) use ( &$cached_value ) {
                $cached_value = $value;
                return true;
            }
        );

        $call_count = 0;
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
            function ( $url, $args ) use ( &$call_count ) {
                $call_count++;
                if ( $call_count <= 2 ) {
                    // Pass 1 (ranged × 2): healthy WP page, no markers → inconclusive.
                    return [
                        'response' => [ 'code' => 200 ],
                        'headers'  => [],
                        'body'     => '<!doctype html><html><head><meta name="generator" content="WordPress 6.4"></head>'
                                    . '<body>plain content</body></html>',
                    ];
                }
                // Pass 2a (URL1 full body): Breeze end-of-body marker in tail → class_bc_only.
                return [
                    'response' => [ 'code' => 200 ],
                    'headers'  => [],
                    'body'     => '<!doctype html><html><head><meta name="generator" content="WordPress 6.4"></head>'
                                . '<body>' . str_repeat( 'x', 5000 ) . '</body></html>'
                                . '<!-- Cache served by breeze -->',
                ];
            }
        );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( function ( $r ) { return false; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( function ( $r ) { return $r['response']['code']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturnUsing( function ( $r ) { return $r['headers']; } );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( function ( $r ) { return $r['body']; } );

        // First call: populates 24h transient via Pass 2.
        $result1 = PluginDetector::probe_target_stack(
            'https://cached-breeze.example/',
            'https://cached-breeze.example/page-2/',
            12
        );
        $this->assertSame( 'class_bc_only', $result1['outcome'] );
        $this->assertSame( 3, $call_count, 'First call should fire 3 attempts (Pass 1×2 + Pass 2a)' );

        // Second call: cache hit, zero additional wp_remote_get calls.
        $result2 = PluginDetector::probe_target_stack(
            'https://cached-breeze.example/',
            'https://cached-breeze.example/page-2/',
            12
        );
        $this->assertSame( 'class_bc_only', $result2['outcome'] );
        $detected_names = array_column( $result2['detected'] ?? [], 'name' );
        $this->assertContains( 'Breeze', $detected_names );
        $this->assertSame( 3, $call_count, 'Second call should hit cache; no additional wp_remote_get' );
        $this->assertTrue( $result2['cache_hit'] );
    }

    // -----------------------------------------------------------------
    // AAS 1.4.0 Task 1 — extract_non_text_zones skeleton tests.
    // Tests invoke via the __test_extract_non_text_zones public seam
    // (matching the __test_body_match / __test_header_match convention).
    // -----------------------------------------------------------------

    /**
     * AC-T2-1 — empty string input returns empty string.
     */
    public function test_extract_non_text_zones_empty_string_returns_empty(): void {
        $r = PluginDetector::__test_extract_non_text_zones( '' );
        $this->assertSame( '', $r );
    }

    /**
     * AC-T2-3 — no <head> AND no <body> → fallback returns input unchanged.
     * Per spec §5.3 fallback rule.
     */
    public function test_extract_non_text_zones_no_head_no_body_falls_back_to_input(): void {
        $input = '<!-- standalone comment --><script>var x=1;</script>';
        $r = PluginDetector::__test_extract_non_text_zones( $input );
        $this->assertSame( $input, $r );
    }

    /**
     * AC-T2-2 — minimal valid HTML with <head> and <body>:
     * head content preserved (via Task 2 zone 1 wholesale capture), visible body text excluded.
     */
    public function test_extract_non_text_zones_minimal_valid_html(): void {
        $html = '<html><head><title>X</title></head><body><p>visible</p></body></html>';
        $r = PluginDetector::__test_extract_non_text_zones( $html );
        $this->assertStringContainsString( '<title>X</title>', $r );
        $this->assertStringNotContainsString( 'visible', $r );
    }

    // -----------------------------------------------------------------
    // AAS 1.4.0 Task 2 — extract_non_text_zones zone-wiring tests.
    // -----------------------------------------------------------------

    /**
     * HTML comments are included; visible body text is excluded.
     */
    public function test_extract_non_text_zones_preserves_html_comments(): void {
        $html = '<html><body><p>Visible</p><!-- Cached at 12345 --></body></html>';
        $r = PluginDetector::__test_extract_non_text_zones( $html );
        $this->assertStringContainsString( 'Cached at 12345', $r );
        $this->assertStringNotContainsString( 'Visible', $r );
    }

    /**
     * <script> content is included; visible body text is excluded.
     */
    public function test_extract_non_text_zones_preserves_script_content(): void {
        $html = '<html><body><script>var path = "/wp-content/cache/flying-press/x.js";</script><p>visible</p></body></html>';
        $r = PluginDetector::__test_extract_non_text_zones( $html );
        $this->assertStringContainsString( '/wp-content/cache/flying-press/x.js', $r );
        $this->assertStringNotContainsString( 'visible', $r );
    }

    /**
     * <style> content is included; visible body text is excluded.
     */
    public function test_extract_non_text_zones_preserves_style_content(): void {
        $html = '<html><body><style>.flying-press-lazy-bg { background: none; }</style><p>visible</p></body></html>';
        $r = PluginDetector::__test_extract_non_text_zones( $html );
        $this->assertStringContainsString( 'flying-press-lazy-bg', $r );
        $this->assertStringNotContainsString( 'visible', $r );
    }

    /**
     * <head>...</head> is included wholesale; visible body text is excluded.
     */
    public function test_extract_non_text_zones_preserves_head_zone_wholesale(): void {
        $html = '<html><head><meta name="generator" content="LiteSpeed Cache"><link rel="stylesheet" href="/wp-content/cache/litespeed/x.css"></head><body><p>visible</p></body></html>';
        $r = PluginDetector::__test_extract_non_text_zones( $html );
        $this->assertStringContainsString( 'LiteSpeed Cache', $r );
        $this->assertStringContainsString( '/wp-content/cache/litespeed/', $r );
        $this->assertStringNotContainsString( 'visible', $r );
    }

    // -----------------------------------------------------------------
    // AAS 1.4.0 Task 3 — extract_non_text_zones attribute whitelist tests.
    // d-review Mi3: name/content added so OG/meta-generator markers survive.
    // -----------------------------------------------------------------

    /**
     * class and id attributes are extracted; visible text is excluded.
     */
    public function test_extract_non_text_zones_preserves_class_id_attributes(): void {
        $html = '<html><body><div class="flying-press-lazy-bg" id="flying-press-css"><p>visible</p></div></body></html>';
        $r = PluginDetector::__test_extract_non_text_zones( $html );
        $this->assertStringContainsString( 'flying-press-lazy-bg', $r );
        $this->assertStringContainsString( 'flying-press-css', $r );
        $this->assertStringNotContainsString( 'visible', $r );
    }

    /**
     * src, href, and data-* attributes are extracted; visible text is excluded.
     */
    public function test_extract_non_text_zones_preserves_src_href_data_attributes(): void {
        $html = '<html><body><a href="/wp-content/cache/flying-press/x.css" data-wpacu="1">link</a><img src="/wp-content/plugins/perfmatters/icon.png"></body></html>';
        $r = PluginDetector::__test_extract_non_text_zones( $html );
        $this->assertStringContainsString( '/wp-content/cache/flying-press/', $r );
        $this->assertStringContainsString( 'data-wpacu', $r );
        $this->assertStringContainsString( '/wp-content/plugins/perfmatters/', $r );
        $this->assertStringNotContainsString( 'link', $r );
    }

    /**
     * name and content attributes are extracted (d-review Mi3).
     * OG/meta-generator markers in <body> must survive the non-text-zone pass.
     */
    public function test_extract_non_text_zones_preserves_name_content_attributes_mi3(): void {
        // d-review Mi3: name/content attributes added so OG/meta-generator markers in <body> survive
        $html = '<html><body><meta name="generator" content="WP Rocket caching plugin"><p>visible</p></body></html>';
        $r = PluginDetector::__test_extract_non_text_zones( $html );
        $this->assertStringContainsString( 'WP Rocket', $r );
        $this->assertStringNotContainsString( 'visible', $r );
    }

    /**
     * Zone 6 negative boundary — `style` attribute is intentionally NOT in the whitelist.
     * A url() reference inside an inline style must NOT survive into the scoped output, because inline
     * CSS commonly carries unrelated URLs that would false-positive against target_body_pattern.
     * Hardens the whitelist decision flagged in Task 3 code-quality review.
     */
    public function test_extract_non_text_zones_excludes_style_attribute(): void {
        $html = '<html><body><div style="background-image:url(/wp-content/cache/flying-press/x.png)"><p>visible</p></div></body></html>';
        $r = PluginDetector::__test_extract_non_text_zones( $html );
        $this->assertStringNotContainsString( '/wp-content/cache/flying-press/', $r,
            'style attribute URLs must NOT appear in scoped output (style is excluded from whitelist).' );
        $this->assertStringNotContainsString( 'visible', $r );
    }

    // -----------------------------------------------------------------
    // AAS 1.4.0 Task 4 — extract_non_text_zones <noscript> zone (d-review Mi4).
    // -----------------------------------------------------------------

    /**
     * <noscript> inner text is preserved; visible body text is excluded.
     * d-review Mi4: noscript inner text may contain plugin fingerprints not in attributes.
     */
    public function test_extract_non_text_zones_preserves_noscript_inner_text_mi4(): void {
        // The text content inside <noscript> may contain plugin fingerprints that aren't in attributes.
        $html = '<html><body><noscript>This page was Cached by FlyingPress</noscript><p>visible body text</p></body></html>';
        $r = PluginDetector::__test_extract_non_text_zones( $html );
        $this->assertStringContainsString( 'Cached by FlyingPress', $r );
        $this->assertStringNotContainsString( 'visible body text', $r );
    }

    // AAS 1.4.0 Task 5 — body_match_pattern null/empty/malformed-PCRE contract (AC-T2-4).
    // Tests invoke via the __test_body_match_pattern public seam.

    public function test_body_match_pattern_empty_pattern_returns_false(): void {
        $r = PluginDetector::__test_body_match_pattern( 'any scoped body', null );
        $this->assertFalse( $r );
        $r2 = PluginDetector::__test_body_match_pattern( 'any scoped body', '' );
        $this->assertFalse( $r2 );
    }

    public function test_body_match_pattern_null_scoped_body_returns_false(): void {
        $r = PluginDetector::__test_body_match_pattern( null, '/\bfoo\b/i' );
        $this->assertFalse( $r );
    }

    public function test_body_match_pattern_empty_scoped_body_returns_false(): void {
        $r = PluginDetector::__test_body_match_pattern( '', '/\bfoo\b/i' );
        $this->assertFalse( $r );
    }

    public function test_body_match_pattern_malformed_pcre_returns_false(): void {
        // Unclosed group — preg_match returns false (not 0); we should swallow and return false.
        $r = PluginDetector::__test_body_match_pattern( 'foo bar', '/(unclosed/' );
        $this->assertFalse( $r );
    }

    public function test_body_match_pattern_matches_on_scoped_input(): void {
        $scoped = '<!-- Powered by FlyingPress for lightning-fast performance -->';
        $r = PluginDetector::__test_body_match_pattern( $scoped, '/\bflying[- _]?press\b/i' );
        $this->assertTrue( $r );
    }

    public function test_body_match_pattern_no_match_returns_false(): void {
        $scoped = '<!-- This site uses WP Rocket -->';
        $r = PluginDetector::__test_body_match_pattern( $scoped, '/\bflying[- _]?press\b/i' );
        $this->assertFalse( $r );
    }

    public function test_body_match_pattern_case_insensitivity_via_i_flag(): void {
        // Patterns in OPTIMIZERS always carry /i — assert the helper preserves it.
        $scoped = 'POWERED BY FLYINGPRESS';
        $r = PluginDetector::__test_body_match_pattern( $scoped, '/\bflying[- _]?press\b/i' );
        $this->assertTrue( $r );
    }

    // -----------------------------------------------------------------
    // AAS 1.4.0 Task 8 — AC-T2-6 hoist-preservation test (d-review M3).
    // -----------------------------------------------------------------

    /**
     * AC-T2-6 — d-review M3 hoist is load-bearing. extract_non_text_zones must be
     * invoked AT MOST ONCE per single_probe_attempt call (NOT 14× inside the
     * OPTIMIZERS loop). A regression here would make Tier 2 cost analysis in
     * spec §6.4.2 fail (~14× cost increase on 2MB bodies).
     *
     * Strategy: stub wp_remote_get to return a body that triggers the loop, reset
     * $extract_call_count before the call, then assert <=4 after probe_target_stack.
     * probe_target_stack runs up to 4 single_probe_attempt invocations
     * (Pass-1a, Pass-1b, Pass-2a, Pass-2b). Each invokes extract_non_text_zones
     * exactly ONCE per probe (NOT 14 times per probe).
     * For this single-URL fixture (no fallback, Pass 1 resolves), only Pass-1a fires
     * → expected count is 1. Without hoist: 14 invocations. With hoist: 1 invocation.
     */
    public function test_extract_non_text_zones_called_at_most_once_per_probe_act2t6(): void {
        PluginDetector::$extract_call_count = 0;

        $body = '<html><body><p>visible</p><!-- Powered by FlyingPress -->'
              . '<script>/* WP Rocket optimisation */</script>'
              . '</body></html>'
              . str_repeat( 'X', 50000 );

        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
            function ( $url, $args ) use ( $body ) {
                return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => $body ];
            }
        );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturn( [] );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( function () use ( $body ) {
            return $body;
        } );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );

        PluginDetector::probe_target_stack( 'https://example.com/', null, 12 );

        // Single-URL fixture, Pass 1 resolves → exactly 1 extract_non_text_zones call.
        // 14+ = hoist broken (per-plugin invocation inside OPTIMIZERS loop); 0 = mock not invoking probe path.
        $this->assertSame( 1, PluginDetector::$extract_call_count,
            'AC-T2-6: single URL, Pass 1 resolves → expected 1 extract_non_text_zones call; got '
            . PluginDetector::$extract_call_count
            . '. If 14+, the hoist in single_probe_attempt is broken (spec §6.4.2 cost analysis fails). '
            . 'If 0, the mock chain is not reaching single_probe_attempt.'
        );
    }

    // -----------------------------------------------------------------
    // AAS 1.4.0 Task 9 — target_headers data-provider tests (AC-T1-1/T1-2/T1-3).
    // Verifies header_match detects each plugin via its documented header patterns,
    // and that the WP Fastest Cache phantom pattern has been removed.
    // -----------------------------------------------------------------

    /**
     * AC-T1-1 + AC-T1-2 — every plugin with new/expanded target_headers is detectable
     * via header_match against a synthetic header fixture containing the documented header.
     *
     * @dataProvider target_header_fixtures
     */
    public function test_target_headers_detect_each_plugin( string $plugin_name, array $headers ): void {
        $entry = $this->getOptimizerEntry( $plugin_name );
        $r = PluginDetector::__test_header_match( $headers, $entry['target_headers'] );
        $this->assertTrue( $r, "Plugin '$plugin_name' must be detected via headers " . json_encode( $headers ) );
    }

    public function target_header_fixtures(): array {
        return [
            'WP Rocket via x-wp-rocket-cache'                => [ 'WP Rocket', [ 'x-wp-rocket-cache' => 'HIT' ] ],
            'WP Rocket via x-rocket-nginx-bypass'            => [ 'WP Rocket', [ 'x-rocket-nginx-bypass' => 'No' ] ],
            'NitroPack via x-nitro-cache'                    => [ 'NitroPack', [ 'x-nitro-cache' => 'HIT' ] ],
            'NitroPack via x-nitro-cache-from'               => [ 'NitroPack', [ 'x-nitro-cache-from' => 'drop-in' ] ],
            'NitroPack via x-nitro-rev'                      => [ 'NitroPack', [ 'x-nitro-rev' => '5b74026' ] ],
            'LiteSpeed Cache via x-litespeed-cache'          => [ 'LiteSpeed Cache', [ 'x-litespeed-cache' => 'hit' ] ],
            'LiteSpeed Cache via x-litespeed-cache-control'  => [ 'LiteSpeed Cache', [ 'x-litespeed-cache-control' => 'no-cache' ] ],
            'W3 Total Cache via x-w3tc-cached-by'            => [ 'W3 Total Cache', [ 'x-w3tc-cached-by' => 'memcached' ] ],
            'W3 Total Cache via x-w3tc-page-cache'           => [ 'W3 Total Cache', [ 'x-w3tc-page-cache' => 'true' ] ],
            'W3 Total Cache via x-w3tc-cdn'                  => [ 'W3 Total Cache', [ 'x-w3tc-cdn' => 'bunny' ] ],
            'W3 Total Cache via x-powered-by combo'          => [ 'W3 Total Cache', [ 'x-powered-by' => 'W3 Total Cache/2.9.4' ] ],
            'Breeze via x-cache-handler'                     => [ 'Breeze', [ 'x-cache-handler' => 'breeze' ] ],
            'Breeze via x-breeze-cache-write'                => [ 'Breeze', [ 'x-breeze-cache-write' => 'SUCCESS' ] ],
            'Breeze via x-breeze-cache'                      => [ 'Breeze', [ 'x-breeze-cache' => 'BYPASSED-CIRCUIT-BREAKER' ] ],
            'Breeze via x-breeze-circuit-breaker'            => [ 'Breeze', [ 'x-breeze-circuit-breaker' => 'OPEN' ] ],
            'Cache Enabler via x-cache-handler'              => [ 'Cache Enabler', [ 'x-cache-handler' => 'cache-enabler-engine' ] ],
            'Swift Performance via swift3'                   => [ 'Swift Performance', [ 'swift3' => 'HIT/Proxy' ] ],
            'Swift Performance via x-cache-status identical' => [ 'Swift Performance', [ 'x-cache-status' => 'identical' ] ],
            'Hummingbird via hummingbird-cache'              => [ 'Hummingbird', [ 'hummingbird-cache' => 'Served' ] ],
            'FlyingPress via x-flying-press-cache'           => [ 'FlyingPress', [ 'x-flying-press-cache' => 'HIT' ] ],
            'FlyingPress via x-flying-press-source'          => [ 'FlyingPress', [ 'x-flying-press-source' => 'Web Server' ] ],
            'SG Optimizer via sg-f-cache'                    => [ 'SiteGround Optimizer', [ 'sg-f-cache' => 'HIT' ] ],
            'SG Optimizer via x-powered-by siteground combo' => [ 'SiteGround Optimizer', [ 'x-powered-by' => 'siteground' ] ],
        ];
    }

    private function getOptimizerEntry( string $name ): array {
        $r = new \ReflectionClass( PluginDetector::class );
        $opt = $r->getConstant( 'OPTIMIZERS' );
        foreach ( $opt as $entry ) {
            if ( ( $entry['name'] ?? '' ) === $name ) {
                return $entry;
            }
        }
        $this->fail( "OPTIMIZERS entry for '$name' not found" );
    }

    // -----------------------------------------------------------------
    // AAS 1.4.0 Task 10 — target_body_pattern data-provider tests (AC-T2-1).
    // Verifies each plugin's target_body_pattern regex matches a representative
    // fingerprint string drawn from real plugin output.
    // -----------------------------------------------------------------

    /**
     * AC-T2-1 — each plugin's target_body_pattern matches against a representative
     * fingerprint string drawn from real plugin output.
     *
     * @dataProvider target_body_pattern_fixtures
     */
    public function test_target_body_pattern_matches_fingerprint( string $plugin_name, string $fingerprint ): void {
        $entry = $this->getOptimizerEntry( $plugin_name );
        $pat = $entry['target_body_pattern'] ?? null;
        $this->assertNotNull( $pat, "Plugin '$plugin_name' must have target_body_pattern set" );
        $r = PluginDetector::__test_body_match_pattern( $fingerprint, $pat );
        $this->assertTrue( $r, "Pattern '$pat' must match '$fingerprint' for plugin '$plugin_name'" );
    }

    public function target_body_pattern_fixtures(): array {
        return [
            'WP Rocket'             => [ 'WP Rocket', 'This website is like a Rocket via wp-rocket cache' ],
            'Perfmatters'           => [ 'Perfmatters', '/wp-content/plugins/perfmatters/js/lazy.js' ],
            'Autoptimize'           => [ 'Autoptimize', '/* autoptimize-compat */' ],
            'NitroPack'             => [ 'NitroPack', '<img src="https://cdn-xy.nitrocdn.com/foo">' ],
            'Asset CleanUp'         => [ 'Asset CleanUp', 'data-wpacu="1"' ],
            'LiteSpeed Cache'       => [ 'LiteSpeed Cache', '<!-- Page generated by LiteSpeed Cache -->' ],
            'WP Fastest Cache'      => [ 'WP Fastest Cache', '<!-- WP Fastest Cache file was created -->' ],
            'W3 Total Cache'        => [ 'W3 Total Cache', 'class="w3tc-asset"' ],
            'Breeze'                => [ 'Breeze', '<!-- Cache served by Breeze -->' ],
            'Cache Enabler'         => [ 'Cache Enabler', '<!-- Cache Enabler by KeyCDN -->' ],
            'Swift Performance'     => [ 'Swift Performance', '<!-- Cached by Swift Performance -->' ],
            'Hummingbird'           => [ 'Hummingbird', '<link href="/wp-content/cache/hummingbird/x.css">' ],
            'FlyingPress'           => [ 'FlyingPress', '<!-- Powered by FlyingPress -->' ],
            'SiteGround Optimizer'  => [ 'SiteGround Optimizer', '<!-- Optimized by SG Optimizer -->' ],
        ];
    }

    // AAS 1.4.0 Task 11 — AC-T2-2 FP corpus regression: target_body_pattern must NOT fire on
    // visible body text that merely mentions the plugin name (review articles, comparison pages).
    // -----------------------------------------------------------------

    /**
     * AC-T2-2 — no target_body_pattern matches against a corpus of HTML fixtures that
     * mention the plugin name in VISIBLE body text WITHOUT using the plugin.
     *
     * This is the production-safety net for Tier 2: extract_non_text_zones() strips
     * visible <body> text before regex matching, so plugin-name mentions in <p>/<h1>/<li>
     * etc. must not trip a match.
     *
     * @dataProvider fp_corpus_fixtures
     */
    public function test_target_body_pattern_does_not_match_fp_corpus( string $plugin_name, string $html ): void {
        $entry = $this->getOptimizerEntry( $plugin_name );
        $pat = $entry['target_body_pattern'] ?? null;
        $this->assertNotNull( $pat );

        // Mirror the production path: slice → extract_non_text_zones → body_match_pattern.
        // Pass 1 (use_range=true) slices first 32KB; fixtures here are <1KB so the slice is the full HTML.
        $body_slice = substr( $html, 0, 32768 );
        $scoped     = PluginDetector::__test_extract_non_text_zones( $body_slice );
        $r          = PluginDetector::__test_body_match_pattern( $scoped, $pat );

        $this->assertFalse( $r,
            "Pattern '$pat' must NOT match plugin-name-in-visible-text fixture for '$plugin_name'. Scoped output: " . substr( $scoped, 0, 500 )
        );
    }

    public function fp_corpus_fixtures(): array {
        // Each fixture: visible body text mentioning the plugin name in user-facing copy
        // (review article, comparison page, FAQ). NO plugin assets, NO plugin output comments,
        // NO plugin headers. The plugin name appears only in <p>/<h1>/<li> visible text.
        // The <title> must NOT contain a plugin-namespaced token that the regex matches (since
        // <head> content is preserved by extract_non_text_zones — titles use deliberately
        // generic copy that avoids the pattern).
        $tpl = function ( string $title, string $body_text ): string {
            return '<!DOCTYPE html><html><head><title>' . $title . '</title></head>'
                 . '<body><h1>' . $title . '</h1><p>' . $body_text . '</p></body></html>';
        };
        return [
            'WP Rocket'             => [ 'WP Rocket', $tpl( 'Cache Plugin Reviews', 'In this guide we compare WP Rocket with other plugins.' ) ],
            'Perfmatters'           => [ 'Perfmatters', $tpl( 'Optimization Tools', 'Perfmatters is a popular WordPress optimization plugin.' ) ],
            'Autoptimize'           => [ 'Autoptimize', $tpl( 'Free WP Plugins', 'Autoptimize handles CSS and JS minification at no cost.' ) ],
            'NitroPack'             => [ 'NitroPack', $tpl( 'Cloud Caching', 'NitroPack offers a cloud-based optimization service.' ) ],
            'Asset CleanUp'         => [ 'Asset CleanUp', $tpl( 'Bloat Removers', 'Asset CleanUp removes unused CSS and JS from your pages.' ) ],
            'LiteSpeed Cache'       => [ 'LiteSpeed Cache', $tpl( 'Server-Side Caching', 'LiteSpeed Cache requires the LSWS server but is otherwise excellent.' ) ],
            'WP Fastest Cache'      => [ 'WP Fastest Cache', $tpl( 'Beginner Cache Plugins', 'WP Fastest Cache is a beginner-friendly cache plugin.' ) ],
            'W3 Total Cache'        => [ 'W3 Total Cache', $tpl( 'Legacy Cache Plugins', 'W3 Total Cache, sometimes shortened to W3TC, has been around since 2009.' ) ],
            'Breeze'                => [ 'Breeze', $tpl( 'Hosting Plugins', 'Breeze is the cache plugin made by Cloudways for their managed hosting.' ) ],
            'Cache Enabler'         => [ 'Cache Enabler', $tpl( 'Minimal Caches', 'Cache Enabler from KeyCDN is a lightweight cache plugin.' ) ],
            'Swift Performance'     => [ 'Swift Performance', $tpl( 'All-In-One Optimization', 'Swift Performance combines caching with image optimization.' ) ],
            'Hummingbird'           => [ 'Hummingbird', $tpl( 'WPMU DEV Plugins', 'Hummingbird is the performance plugin from WPMU DEV.' ) ],
            'FlyingPress'           => [ 'FlyingPress', $tpl( 'Premium Cache Plugins', 'FlyingPress is one of the newer premium cache plugins.' ) ],
            'SiteGround Optimizer'  => [ 'SiteGround Optimizer', $tpl( 'Host Plugins', 'SG Optimizer is the SiteGround cache and optimization plugin.' ) ],
        ];
    }

    /**
     * AC-T1-3 — the removed phantom pattern 'x-cache: wpfc-' (WP Fastest Cache) must not trigger
     * detection; existing body marker 'WP Fastest Cache file was created' must still trigger detection.
     */
    public function test_wp_fastest_cache_phantom_header_removed_act1t3(): void {
        $entry = $this->getOptimizerEntry( 'WP Fastest Cache' );
        // Tighter than assertSame([]): a future contributor may add a legitimate WPFC header
        // (e.g. via .htaccess emission) without re-introducing the specific phantom pattern. See spec §4.2 Mi5.
        $this->assertNotContains( 'x-cache: wpfc-', $entry['target_headers'],
            'Phantom pattern x-cache: wpfc- (no PHP header() emission found in source) must not be present per spec §4.2 Mi5.' );

        // Body marker still works (Pass 1 head scan).
        $body = '<html><body><!-- WP Fastest Cache file was created --></body></html>';
        $r = PluginDetector::__test_body_match( $body, $entry['target_body_markers'], true );
        $this->assertTrue( $r );
    }

    // -------------------------------------------------------------------------
    // Regex backtracking safety lint
    // -------------------------------------------------------------------------

    /**
     * Linear-time guarantee: each target_body_pattern must complete in <100ms
     * against a 100KB pathological string. Prevents catastrophic PCRE backtracking
     * from making it into future pattern additions.
     *
     * Per spec §11 risk register + §15 implementation prerequisite #6.
     *
     * @dataProvider target_body_pattern_lint_fixtures
     */
    public function test_target_body_pattern_linear_time_on_adversarial_input( string $plugin_name ): void {
        $entry = $this->getOptimizerEntry( $plugin_name );
        $pat = $entry['target_body_pattern'] ?? null;
        $this->assertNotNull( $pat );

        // Pathological input: 100KB of repeated 'a' (a common backtracking trap for
        // greedy quantifiers + alternation). Linear-time patterns complete in <1ms;
        // catastrophic backtracking patterns hit PHP's pcre.backtrack_limit and return false
        // (and take seconds).
        $adversarial = str_repeat( 'a', 102400 );

        $start = microtime( true );
        @preg_match( $pat, $adversarial );
        $elapsed_ms = ( microtime( true ) - $start ) * 1000;

        $this->assertLessThan( 100, $elapsed_ms,
            "Pattern '$pat' for plugin '$plugin_name' took {$elapsed_ms}ms on 100KB of 'a'; suspect catastrophic backtracking. "
            . "Tighten the pattern (bounded quantifiers, no nested .*) before merging."
        );
    }

    public function target_body_pattern_lint_fixtures(): array {
        return [
            [ 'WP Rocket' ],
            [ 'Perfmatters' ],
            [ 'Autoptimize' ],
            [ 'NitroPack' ],
            [ 'Asset CleanUp' ],
            [ 'LiteSpeed Cache' ],
            [ 'WP Fastest Cache' ],
            [ 'W3 Total Cache' ],
            [ 'Breeze' ],
            [ 'Cache Enabler' ],
            [ 'Swift Performance' ],
            [ 'Hummingbird' ],
            [ 'FlyingPress' ],
            [ 'SiteGround Optimizer' ],
        ];
    }

    /**
     * AC-T3-1 — synthetic 200KB body with marker at byte ~100,000 (middle of body) must be detected by Pass 2.
     * Complements Task 7's test_body_match_use_range_false_scans_full_body_t3 (100KB body, marker near end).
     */
    public function test_act3_1_pass2_detects_marker_in_middle_of_200kb_body(): void {
        $body = str_repeat( 'X', 100000 ) . '<!-- Optimized by SG Optimizer -->' . str_repeat( 'Y', 100000 );
        $r = PluginDetector::__test_body_match( $body, [ 'Optimized by SG Optimizer' ], false );
        $this->assertTrue( $r, 'Pass 2 (use_range=false) must scan past byte 32768 and find the marker at byte ~100,000' );
    }

    /**
     * AC-T3-2 — synthetic 50KB body with marker at byte 10,000 must be detected by Pass 1 (use_range=true).
     * Confirms the head-scan path still works at intermediate offsets.
     */
    public function test_act3_2_pass1_detects_marker_at_byte_10000_in_50kb_body(): void {
        $body = str_repeat( 'X', 10000 ) . '<!-- Optimized by SG Optimizer -->' . str_repeat( 'Y', 40000 );
        $r = PluginDetector::__test_body_match( $body, [ 'Optimized by SG Optimizer' ], true );
        $this->assertTrue( $r, 'Pass 1 first-32KB head-scan must find marker at byte 10000' );
    }

    /**
     * AC-T3-3 — body_match operates on whatever wp_remote_get returns. The 2MB limit_response_size cap
     * is enforced at the HTTP layer (wp_remote_get) BEFORE body_match sees the body — so body_match
     * itself just receives a (possibly truncated) string and scans it. This test confirms that body_match
     * doesn't crash or misbehave on a 2MB-truncated body when no marker is present.
     *
     * The "marker past 2MB cap" case (i.e., the marker would be at byte >2,097,152 but the response is
     * truncated to 2MB) is exercised indirectly: body_match only sees the truncated 2MB body, so the
     * marker simply isn't there — assertFalse is correct.
     */
    public function test_act3_3_body_match_handles_2mb_capped_body(): void {
        // Simulate a body that wp_remote_get truncated at 2MB. No marker present.
        $truncated_2mb = str_repeat( 'X', 2 * 1024 * 1024 );
        $r = PluginDetector::__test_body_match( $truncated_2mb, [ 'NEVER_PRESENT_MARKER' ], false );
        $this->assertFalse( $r, '2MB body with no marker must NOT match' );

        // And confirm the cap doesn't truncate a marker that fits within 2MB.
        $body_with_marker_near_2mb_end = str_repeat( 'X', 2 * 1024 * 1024 - 100 ) . 'Optimized by SG Optimizer';
        $r2 = PluginDetector::__test_body_match( $body_with_marker_near_2mb_end, [ 'Optimized by SG Optimizer' ], false );
        $this->assertTrue( $r2, '2MB body with marker near end (within cap) must match' );
    }

    // -----------------------------------------------------------------
    // AAS 1.4.0 Task 14 — AC-T1-1 + AC-T3-4 FlyingPress end-to-end integration tests.
    // Closes the original diagnostic trigger of this whole release: pre-1.4.0 returned
    // 'no_clue' because FlyingPress's empty target_headers was the dominant failure cause.
    // -----------------------------------------------------------------

    /**
     * AC-T1-1 + AC-T3-4 — FlyingPress is detected via header on a synthetic response
     * mirroring flyingpress.com's actual response shape (header-driven, no Pass 2 needed).
     *
     * This closes the original diagnostic trigger of this whole release: pre-1.4.0 returned
     * 'no_clue' because FlyingPress's empty target_headers was the dominant failure cause.
     */
    public function test_act1_1_flyingpress_detected_via_header_alone(): void {
        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [
            'response' => [ 'code' => 200 ],
            'headers'  => [
                'x-flying-press-cache'  => 'HIT',
                'x-flying-press-source' => 'Web Server',
                'content-type'          => 'text/html; charset=UTF-8',
            ],
            'body'     => '<html><body><p>visible content here</p></body></html>',
        ] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturn( [
            'x-flying-press-cache'  => 'HIT',
            'x-flying-press-source' => 'Web Server',
            'content-type'          => 'text/html; charset=UTF-8',
        ] );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '<html><body><p>visible content here</p></body></html>' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );

        $r = PluginDetector::probe_target_stack( 'https://flyingpress.com/', null, 12 );

        // outcome may be 'detected', 'class_a_clean', or similar depending on the classifier's view.
        // The KEY assertion is that FlyingPress appears in the detected list with source='header'.
        $this->assertNotEmpty( $r['detected'] ?? [], 'FlyingPress must be detected' );
        $flying_press = null;
        foreach ( $r['detected'] as $d ) {
            if ( ( $d['name'] ?? '' ) === 'FlyingPress' ) {
                $flying_press = $d;
                break;
            }
        }
        $this->assertNotNull( $flying_press, 'FlyingPress must be in the detected list' );
        $this->assertSame( 'header', $flying_press['source'], 'Detection source must be header (not body)' );
    }

    /**
     * AC-T3-4 fallback — even if headers were stripped (e.g., by an aggressive CDN), the body marker
     * path catches FlyingPress via the asset path `/wp-content/cache/flying-press/` in `<link href>`.
     *
     * This validates that the Tier 2 body-pattern fallback (per spec §5.2) provides defense-in-depth.
     */
    public function test_act3_4_flyingpress_detected_via_body_when_headers_stripped(): void {
        $body = '<html><head></head><body><p>visible</p>'
              . '<link href="https://example.com/wp-content/cache/flying-press/x.css">'
              . '</body></html>';
        $headers_no_fingerprint = [ 'content-type' => 'text/html; charset=UTF-8' ];

        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [
            'response' => [ 'code' => 200 ],
            'headers'  => $headers_no_fingerprint,
            'body'     => $body,
        ] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturn( $headers_no_fingerprint );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PORT => 'port' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );

        $r = PluginDetector::probe_target_stack( 'https://example.com/', null, 12 );

        $this->assertNotEmpty( $r['detected'] ?? [], 'FlyingPress must be detected via body fallback' );
        $flying_press = null;
        foreach ( $r['detected'] as $d ) {
            if ( ( $d['name'] ?? '' ) === 'FlyingPress' ) {
                $flying_press = $d;
                break;
            }
        }
        $this->assertNotNull( $flying_press, 'FlyingPress must be in the detected list' );
        $this->assertSame( 'body', $flying_press['source'], 'Detection source must be body (headers stripped)' );
    }

    /**
     * AC-T2-5 — measured combined extract_non_text_zones() + 14× body_match_pattern()
     * time on a synthetic 2MB HTML body completes in ≤50ms p95 on the dev workstation.
     *
     * Validates the d-review M3 hoist preserved through implementation. Without the hoist,
     * the same fixture would take ~14× longer per spec §6.4.2.
     */
    public function test_act2_5_tier2_path_perf_on_2mb_body(): void {
        // Build a 2MB HTML body that exercises the regex zones (head, body, comments, scripts).
        $body = '<html><head><title>Bench</title></head><body>'
              . str_repeat( '<p>filler ' . str_repeat( 'a', 100 ) . '</p>', 18000 )
              . '<!-- Powered by FlyingPress --></body></html>';
        // Pad to exactly 2MB (the limit_response_size ceiling).
        if ( strlen( $body ) < 2 * 1024 * 1024 ) {
            $body .= str_repeat( ' ', 2 * 1024 * 1024 - strlen( $body ) );
        }

        $reflection = new \ReflectionClass( PluginDetector::class );
        $optimizers = $reflection->getConstant( 'OPTIMIZERS' );

        // N=5 runs; p95 = max of 5 runs (lenient — single bad sample bounds the result).
        $times = [];
        for ( $i = 0; $i < 5; $i++ ) {
            $start = microtime( true );
            $scoped = PluginDetector::__test_extract_non_text_zones( $body );
            foreach ( $optimizers as $entry ) {
                PluginDetector::__test_body_match_pattern( $scoped, $entry['target_body_pattern'] ?? null );
            }
            $times[] = ( microtime( true ) - $start ) * 1000;
        }
        sort( $times );
        $p95 = end( $times ); // max of 5 = p95 with small N

        $this->assertLessThan( 50, $p95,
            "Tier 2 path p95 on 2MB body was {$p95}ms; spec §6.4.2 budget is ≤50ms. "
            . "Times: " . json_encode( $times ) . ". "
            . "If this fails, the hoist may be broken OR a target_body_pattern is exhibiting catastrophic backtracking."
        );
    }

    /**
     * Build a WP_Error-shaped object for tests (a simple stdClass with get_error_message()).
     * If a real WP_Error is available in the test bootstrap, prefer that.
     */
    private function make_wp_error( string $code, string $message ) {
        if ( class_exists( 'WP_Error' ) ) {
            return new \WP_Error( $code, $message );
        }
        // Fallback: anonymous object with get_error_message()
        return new class( $code, $message ) {
            public function __construct( public string $code, public string $message ) {}
            public function get_error_message(): string { return $this->message; }
        };
    }
}

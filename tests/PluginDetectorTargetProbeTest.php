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
     * T-N7-A (helper test for body_match tail-only mode).
     * Verifies that body_match with $tail_only=true scans only the last 8KB of body,
     * NOT the first 32KB. End-of-body cache markers (Breeze, WP Rocket, LiteSpeed,
     * etc.) live in HTML comments after </html> — typically in the last few KB.
     */
    public function test_t_n7_helper_body_match_tail_only() {
        // 95KB of filler body with a Breeze marker at byte 95000 (in last 8KB of a ~95031B body).
        $filler_head = str_repeat( 'x', 50000 );      // 50KB of x's
        $filler_mid  = str_repeat( 'y', 45000 );      // 45KB of y's (so total 95KB before marker)
        $marker_tail = '<!-- Cache served by breeze -->';
        $body        = $filler_head . $filler_mid . $marker_tail;

        // Confirm test fixture: marker is BEYOND first 32KB.
        $this->assertGreaterThan( 32768, strpos( $body, 'Cache served by breeze' ) );

        // Default mode (head-only): marker is beyond 32KB → MISS.
        $this->assertFalse(
            PluginDetector::__test_body_match( $body, [ 'Cache served by breeze' ] ),
            'body_match default mode should miss the tail marker'
        );

        // Tail-only mode: scan last 8KB → HIT.
        $this->assertTrue(
            PluginDetector::__test_body_match( $body, [ 'Cache served by breeze' ], true ),
            'body_match with tail_only=true should detect the tail marker'
        );
    }

    /**
     * T-N7-B (helper test for single_probe_attempt parameter expansion).
     * Verifies that single_probe_attempt accepts $use_range + $scan_tail_only
     * parameters and threads them through to wp_remote_get + body_match.
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

        PluginDetector::__test_single_probe_attempt( 'https://example.com/', 12, true, false );

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

        PluginDetector::__test_single_probe_attempt( 'https://example.com/', 12, false, false );

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

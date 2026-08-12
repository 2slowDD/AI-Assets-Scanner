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

    // -------------------------------------------------------------------------
    // AC-RC-1 — attach_resolution: same-site redirect is used as resolved_url
    // -------------------------------------------------------------------------

    public function test_resolution_same_site(): void {
        $this->stub_wp_parse_url();
        $r = PluginDetector::__test_attach_resolution(
            'https://cloudways.com',
            [ 'redirect_final' => 'https://www.cloudways.com/en' ]
        );
        $this->assertSame( 'https://www.cloudways.com/en', $r['resolved_url'] );
        $this->assertSame( 'redirect_final', $r['resolution_source'] );
        $this->assertSame( 'https://cloudways.com', $r['submitted_url'] );
    }

    // -------------------------------------------------------------------------
    // AC-RC-3 — attach_resolution: cross-domain redirect is rejected
    // -------------------------------------------------------------------------

    public function test_resolution_cross_domain_reject(): void {
        $this->stub_wp_parse_url();
        $r = PluginDetector::__test_attach_resolution(
            'https://example.com',
            [ 'redirect_final' => 'https://partner.com/x' ]
        );
        $this->assertSame( 'https://example.com', $r['resolved_url'] );
        $this->assertSame( 'cross_domain_reject', $r['resolution_source'] );
    }

    // -------------------------------------------------------------------------
    // AC-RC-1 (no-redirect branch) — redirect_final same as $url → source=none
    // -------------------------------------------------------------------------

    public function test_resolution_no_redirect(): void {
        $this->stub_wp_parse_url();
        $r = PluginDetector::__test_attach_resolution(
            'https://x.com/p',
            [ 'redirect_final' => 'https://x.com/p' ]
        );
        $this->assertSame( 'https://x.com/p', $r['resolved_url'] );
        $this->assertSame( 'none', $r['resolution_source'] );
    }

    // =========================================================================
    // Spec §4.2 — the cache split. One host-keyed entry used to carry two facts
    // with different natural scopes: detection (host-scoped, correct) and
    // resolution (URL-scoped, fabricated for every path but the probed one).
    //
    // ⚠️ P17 — every behavioural case below drives the REAL probe_target_stack()
    // with a wp_remote_get call counter. Request counts are pinned as hard as
    // outcomes: "right resolved_url, but it cost a request" and "right
    // resolved_url, but it came from an injected map" are both failures here.
    // =========================================================================

    /**
     * The host-keyed detection entry an earlier scan of https://host.com/ left behind.
     * Carries all six AC-7 detection keys plus its own resolution, so a merge that
     * overwrote detection — or that carried $url1's redirect_final onto another path's
     * telemetry line — is visible in the assertions.
     */
    private function host_entry( array $overrides = [] ): array {
        return array_merge( [
            'outcome'             => 'class_a_clean',
            'detected'            => [ 'litespeed' => [ 'name' => 'LiteSpeed Cache', 'class' => 'A' ] ],
            'security_stacks'     => [ 'cloudflare' ],
            'is_wordpress'        => true,
            'page_cache_detected' => true,
            'bypass_suffixes'     => [ 'LSCWP_CTRL=before_optm' ],
            'submitted_url'       => 'https://host.com/',
            'resolved_url'        => 'https://host.com/',
            'resolution_source'   => 'none',
            'redirect_final'      => 'https://host.com/',
        ], $overrides );
    }

    /**
     * The host detection key for https://host.com:443, built by the SAME builder the
     * production code uses. Never a hardcoded 'cu_scanner_target_stack_v<N>_' literal:
     * this guard spent a schema bump silently green because it hardcoded `_v2_`
     * (FU-AAS-DEAD-GUARD-HARDCODED-CACHE-KEY).
     */
    private function host_key(): string {
        return PluginDetector::__test_build_cache_key( 'https', 'host.com', '443' );
    }

    /** The per-URL resolution key, via the one normalisation site. */
    private function url_key( string $url ): string {
        return PluginDetector::__test_build_url_resolution_key( $url );
    }

    /**
     * A wp_remote_get response carrying a post-redirect final URL — the exact shape
     * extract_final_url() reads (cf. test_extract_final_url_reads_response_object).
     * wp_remote_get follows the 301 itself (redirection => 3), so a redirected fetch
     * is still ONE request; the hop shows up only as this final URL.
     */
    private function http_response_with_final_url( string $final_url ) {
        $url_obj = new class( $final_url ) { public function __construct( public string $url ) {} };
        return new class( $url_obj ) {
            public function __construct( private $o ) {}
            public function get_response_object() { return $this->o; }
        };
    }

    /**
     * Counting probe stub — the house idiom from PluginDetectorTargetProbeTest::
     * stub_counting_probe() (:359), plus the two things these cases need: a real
     * redirect target (so redirect_final is produced by the code under test, not
     * injected) and a capture of every set_transient() call as [ key, value, ttl ].
     *
     * @param string|null   $final_url post-redirect URL, or null for "no redirect".
     * @param callable|null $reader    get_transient() stub; defaults to a cold cache.
     */
    private function stub_probe_response_counting(
        array $headers,
        string $body,
        ?string $final_url,
        int &$calls,
        array &$writes,
        ?callable $reader = null,
        int $status = 200
    ): void {
        $this->stub_wp_parse_url();
        WP_Mock::userFunction( 'get_transient' )->andReturnUsing( $reader ?? fn( $key ) => false );
        WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
            function ( $key, $value, $ttl = 0 ) use ( &$writes ) {
                $writes[] = [ $key, $value, $ttl ];
                return true;
            }
        );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        $http_response = $final_url === null ? null : $this->http_response_with_final_url( $final_url );
        WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
            function ( $url, $args ) use ( &$calls, $headers, $body, $status, $http_response ) {
                $calls++;
                $r = [ 'response' => [ 'code' => $status ], 'headers' => $headers, 'body' => $body ];
                if ( null !== $http_response ) {
                    $r['http_response'] = $http_response;
                }
                return $r;
            }
        );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( $status );
        WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturnUsing( fn( $r ) => $r['headers'] );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( fn( $r ) => $r['body'] );
    }

    /** A plain non-WP page — deliberately NOTHING like the cached detection verdict (AC-7). */
    private const PROBE_BODY = '<!doctype html><html><head><title>Plans</title></head><body><h1>Plans</h1></body></html>';

    // -------------------------------------------------------------------------
    // AC-9 Case A (= AC-1 + AC-7 + AC-15/redirect_final) — the shape that bites.
    // Cross-path hit, cached suffixes non-empty, no per-URL entry: spec §4.2 step 2
    // spends exactly one request so the suffix is appended to a RESOLVED URL.
    // -------------------------------------------------------------------------

    public function test_cross_path_hit_with_suffix_probes_and_resolves(): void {
        $host   = $this->host_entry();
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html' ],
            self::PROBE_BODY,
            'https://host.com/plans/pricing/',
            $calls,
            $writes,
            fn( $key ) => $key === $this->host_key() ? $host : false
        );

        $r = PluginDetector::probe_target_stack( 'https://host.com/pricing/' );

        $this->assertTrue( $r['cache_hit'], 'detection still came from the host entry' );
        $this->assertSame( 'https://host.com/plans/pricing/', $r['resolved_url'],
            'the 301 target for THIS path — not the identity URL the old code fabricated' );
        $this->assertSame( 'redirect_final', $r['resolution_source'] );
        $this->assertNull( $r['redirect_final'],
            "the cached redirect_final describes \$url1's probe; carrying it here would put one URL's redirect on another URL's telemetry line" );

        // AC-7 — detection is host-scoped and is NEVER re-derived. The probe fixture above
        // detects nothing at all, so a merge of the whole probe result reddens every key.
        foreach ( [ 'outcome', 'detected', 'security_stacks', 'is_wordpress', 'page_cache_detected', 'bypass_suffixes' ] as $k ) {
            $this->assertSame( $host[ $k ], $r[ $k ], "detection key '$k' must survive the resolution probe untouched" );
        }

        $this->assertSame( 1, $calls, 'step 2 spends exactly ONE request — never zero (the defect), never two' );

        // AC-1 / M6 — the per-URL key is written, the host key is NEVER rewritten here:
        // persisting the merged copy would overwrite the host entry's submitted_url and
        // destroy the previously-resolved path's resolution (cross-path ping-pong).
        $this->assertCount( 1, $writes, 'exactly one transient write on the hit path' );
        [ $key, $value, $ttl ] = $writes[0];
        $this->assertSame( $this->url_key( 'https://host.com/pricing/' ), $key );
        $this->assertNotSame( $this->host_key(), $key, 'the host entry must never be rewritten on the hit path' );
        $this->assertSame(
            [ 'resolved_url' => 'https://host.com/plans/pricing/', 'resolution_source' => 'redirect_final' ],
            $value,
            'the per-URL entry stores EXACTLY two keys — never a copy of the host verdict'
        );
        $this->assertSame( 2 * HOUR_IN_SECONDS, $ttl );
    }

    // -------------------------------------------------------------------------
    // AC-9 Case B (= AC-3) — suffix-less host: spec §4.2 step 3. Without a suffix the
    // dispatched URL is bare, the origin's own 301 applies and the worker follows it,
    // so a request here buys nothing. Nothing learned ⇒ nothing persisted.
    // -------------------------------------------------------------------------

    public function test_cross_path_hit_without_suffix_stays_identity_not_probed(): void {
        // Only bypass_suffixes gates step 2; the other keys ride through untouched.
        $host   = $this->host_entry( [ 'bypass_suffixes' => [], 'detected' => [] ] );
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html' ],
            self::PROBE_BODY,
            'https://host.com/plans/pricing/',
            $calls,
            $writes,
            fn( $key ) => $key === $this->host_key() ? $host : false
        );

        $r = PluginDetector::probe_target_stack( 'https://host.com/pricing/' );

        $this->assertTrue( $r['cache_hit'] );
        $this->assertSame( 'https://host.com/pricing/', $r['resolved_url'], 'identity — today\'s behaviour, deliberately' );
        $this->assertSame( 'not_probed', $r['resolution_source'], 'honest: this URL was never probed, so no resolution is claimed' );
        $this->assertNull( $r['redirect_final'] );
        $this->assertSame( 0, $calls, 'no suffix will be appended ⇒ no request is warranted' );
        $this->assertSame( [], $writes, 'a persisted not_probed entry would short-circuit step 2 for a full TTL (C4b)' );
    }

    // -------------------------------------------------------------------------
    // AC-2 — spec §4.2 step 1: a warm per-URL entry answers for free.
    // -------------------------------------------------------------------------

    public function test_cross_path_hit_with_warm_per_url_entry_is_free(): void {
        $host   = $this->host_entry();
        $entry  = [ 'resolved_url' => 'https://host.com/plans/pricing/', 'resolution_source' => 'redirect_final' ];
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html' ],
            self::PROBE_BODY,
            'https://host.com/plans/pricing/',
            $calls,
            $writes,
            function ( $key ) use ( $host, $entry ) {
                if ( $key === $this->host_key() ) {
                    return $host;
                }
                return $key === $this->url_key( 'https://host.com/pricing/' ) ? $entry : false;
            }
        );

        $r = PluginDetector::probe_target_stack( 'https://host.com/pricing/' );

        $this->assertTrue( $r['cache_hit'] );
        $this->assertSame( 'https://host.com/plans/pricing/', $r['resolved_url'] );
        $this->assertSame( 'redirect_final', $r['resolution_source'] );
        $this->assertNull( $r['redirect_final'] );
        $this->assertSame( 0, $calls, 'a warm per-URL entry costs ZERO HTTP — that is the whole point of storing it' );
        $this->assertSame( [], $writes, 'nothing new was learned, so nothing is rewritten' );
    }

    // -------------------------------------------------------------------------
    // AC-4 — same path as the cached submitted_url: unchanged behaviour, and
    // redirect_final is NOT nulled (here it describes exactly the right URL).
    // -------------------------------------------------------------------------

    public function test_same_path_hit_unchanged(): void {
        $host = $this->host_entry( [
            'submitted_url'     => 'https://host.com/pricing/',
            'resolved_url'      => 'https://host.com/plans/pricing/',
            'resolution_source' => 'redirect_final',
            'redirect_final'    => 'https://host.com/plans/pricing/',
        ] );
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html' ],
            self::PROBE_BODY,
            'https://host.com/somewhere-else/',
            $calls,
            $writes,
            fn( $key ) => $key === $this->host_key() ? $host : false
        );

        $r = PluginDetector::probe_target_stack( 'https://host.com/pricing/' );

        $this->assertTrue( $r['cache_hit'] );
        $this->assertSame( 'https://host.com/plans/pricing/', $r['resolved_url'], 'the cached resolution describes THIS url' );
        $this->assertSame( 'redirect_final', $r['resolution_source'] );
        $this->assertSame( 'https://host.com/plans/pricing/', $r['redirect_final'],
            'the same-path return must stay byte-identical to pre-split behaviour — nulling here would contradict AC-4' );
        $this->assertSame( 0, $calls, 'the same-path hit must never fall through into the step 1-3 ladder' );
        $this->assertSame( [], $writes );
    }

    // -------------------------------------------------------------------------
    // AC-5 — cache MISS: the probe already happened, so the per-URL entry is written
    // for free, ALONGSIDE (never instead of) the host entry. The request count is the
    // fixture's pre-change count, MEASURED against the unmodified code: 1.
    // -------------------------------------------------------------------------

    public function test_miss_path_writes_per_url_entry(): void {
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html', 'x-litespeed-cache' => 'hit' ],
            '<!doctype html><html><head><link rel="stylesheet" href="/wp-content/themes/t/style.css"></head><body><h1>Plans</h1></body></html>',
            'https://host.com/plans/pricing/',
            $calls,
            $writes
        );

        $r = PluginDetector::probe_target_stack( 'https://host.com/pricing/' );

        $this->assertFalse( $r['cache_hit'] );
        $this->assertSame( 'class_a_clean', $r['outcome'] );
        $this->assertSame( 'https://host.com/plans/pricing/', $r['resolved_url'] );
        $this->assertSame( 1, $calls, 'the per-URL write adds ZERO HTTP — measured pre-change on this exact fixture: 1' );

        // Spec §7.1 mutation gate, Finding 2: $by_key collapses duplicates by key, so it is
        // structurally blind to a double-write — writing the per-URL entry twice here ran green
        // against all 845 tests. The hit path already counts the RAW array (:318); the miss
        // path must too, or "wrote it twice" and "wrote it once" are the same assertion.
        $this->assertCount( 2, $writes, 'exactly two writes — a duplicate is a wasted round trip to the object cache' );

        $by_key = [];
        foreach ( $writes as [ $k, $v, $t ] ) {
            $by_key[ $k ] = [ 'value' => $v, 'ttl' => $t ];
        }
        $this->assertCount( 2, $by_key, 'both stores are written on a miss: host detection + per-URL resolution' );

        $this->assertArrayHasKey( $this->host_key(), $by_key, 'the pre-existing host entry must still be written' );
        $this->assertSame( DAY_IN_SECONDS, $by_key[ $this->host_key() ]['ttl'], 'the host tier is untouched by this spec' );

        $url_key = $this->url_key( 'https://host.com/pricing/' );
        $this->assertArrayHasKey( $url_key, $by_key, 'the miss path is the writer that fires on a host FIRST probe' );
        $this->assertSame(
            [ 'resolved_url' => 'https://host.com/plans/pricing/', 'resolution_source' => 'redirect_final' ],
            $by_key[ $url_key ]['value']
        );
        $this->assertSame( 2 * HOUR_IN_SECONDS, $by_key[ $url_key ]['ttl'] );
    }

    // -------------------------------------------------------------------------
    // AC-6 — a cross-domain 301 is rejected, identity is retained, and the rejection
    // is cached for 2 h like any other definitive state (§4.1 TTL table).
    // -------------------------------------------------------------------------

    public function test_cross_domain_redirect_rejected_and_cached_2h(): void {
        $host   = $this->host_entry();
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html' ],
            self::PROBE_BODY,
            'https://partner.example/x',
            $calls,
            $writes,
            fn( $key ) => $key === $this->host_key() ? $host : false
        );

        $r = PluginDetector::probe_target_stack( 'https://host.com/pricing/' );

        $this->assertSame( 'https://host.com/pricing/', $r['resolved_url'], 'an off-site hop is never adopted — fail closed' );
        $this->assertSame( 'cross_domain_reject', $r['resolution_source'] );
        $this->assertSame( 1, $calls );
        $this->assertCount( 1, $writes );
        [ $key, $value, $ttl ] = $writes[0];
        $this->assertSame( $this->url_key( 'https://host.com/pricing/' ), $key );
        $this->assertSame(
            [ 'resolved_url' => 'https://host.com/pricing/', 'resolution_source' => 'cross_domain_reject' ],
            $value
        );
        $this->assertSame( 2 * HOUR_IN_SECONDS, $ttl, 'a rejection is a definitive answer — 2 h, not 24 h and not 15 min' );
    }

    // -------------------------------------------------------------------------
    // AC-8 — a failed resolution probe learned nothing, so it takes the short tier
    // and self-heals on the next scan instead of pinning a fabricated 'none' for 2 h.
    // -------------------------------------------------------------------------

    public function test_probe_failure_gets_15min_ttl(): void {
        $host   = $this->host_entry();
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html' ],
            '',
            null,
            $calls,
            $writes,
            fn( $key ) => $key === $this->host_key() ? $host : false,
            404
        );

        $r = PluginDetector::probe_target_stack( 'https://host.com/pricing/' );

        $this->assertSame( 'https://host.com/pricing/', $r['resolved_url'], 'identity is the honest fallback when the probe failed' );
        $this->assertSame( 'probe_failed', $r['resolution_source'], 'a 4xx did not establish "no redirect" — it established nothing' );
        $this->assertSame( 1, $calls );
        $this->assertCount( 1, $writes );
        [ $key, $value, $ttl ] = $writes[0];
        $this->assertSame( $this->url_key( 'https://host.com/pricing/' ), $key );
        $this->assertSame( 'probe_failed', $value['resolution_source'] );
        $this->assertSame( 15 * MINUTE_IN_SECONDS, $ttl, 'next scan must retry — a failure pinned for 2 h is the bug in a new coat' );
    }

    // -------------------------------------------------------------------------
    // AC-12 — the C4b sequence. Step 3 must leave NOTHING behind: when the host's
    // suffix list later fills in, step 2 has to fire. If step 3 had persisted a
    // 'not_probed' entry, step 1 would short-circuit step 2 for a full TTL and the
    // original defect would be reproduced AND cached.
    // -------------------------------------------------------------------------

    public function test_step3_leaves_nothing_that_blocks_later_step2(): void {
        $host   = $this->host_entry( [ 'bypass_suffixes' => [], 'detected' => [] ] );
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html' ],
            self::PROBE_BODY,
            'https://host.com/plans/pricing/',
            $calls,
            $writes,
            function ( $key ) use ( &$host ) {
                // Only ever answers for the HOST key — the per-URL key stays cold throughout,
                // which is exactly the claim under test.
                return $key === $this->host_key() ? $host : false;
            }
        );

        // Pass 1 — suffix-less host: step 3, free, silent.
        $r1 = PluginDetector::probe_target_stack( 'https://host.com/pricing/' );
        $this->assertSame( 'not_probed', $r1['resolution_source'] );
        $this->assertSame( 0, $calls );
        $this->assertSame( [], $writes, 'step 3 must write nothing at all' );

        // The host entry refreshes and an optimizer is now detected.
        $host['bypass_suffixes'] = [ 'LSCWP_CTRL=before_optm' ];

        // Pass 2 — same path, now suffix-bearing: step 2 must fire and probe.
        $r2 = PluginDetector::probe_target_stack( 'https://host.com/pricing/' );
        $this->assertSame( 'https://host.com/plans/pricing/', $r2['resolved_url'] );
        $this->assertSame( 'redirect_final', $r2['resolution_source'] );
        $this->assertSame( 1, $calls, 'the earlier step-3 pass must not have short-circuited this probe' );
        $this->assertCount( 1, $writes );
        $this->assertSame( $this->url_key( 'https://host.com/pricing/' ), $writes[0][0] );
    }

    // -------------------------------------------------------------------------
    // AC-15 — every definitive resolution state is cached for 2 h.
    //
    // Discharged from the TTL argument CAPTURED off a real step-2 set_transient()
    // call, deliberately not via the __test_url_resolution_ttl() seam: AC-15's Given
    // is behavioural ("written by the miss path or step 2"), and the seam already has
    // its own unit test (PluginDetectorUrlResolutionKeyTest::test_ttl_tiers).
    //
    // @dataProvider definitive_resolution_states
    // -------------------------------------------------------------------------

    /** @dataProvider definitive_resolution_states */
    public function test_definitive_states_get_2h_ttl( ?string $final_url, string $expected_source, string $expected_resolved ): void {
        $host   = $this->host_entry();
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html' ],
            self::PROBE_BODY,
            $final_url,
            $calls,
            $writes,
            fn( $key ) => $key === $this->host_key() ? $host : false
        );

        $r = PluginDetector::probe_target_stack( 'https://host.com/pricing/' );

        $this->assertSame( $expected_source, $r['resolution_source'] );
        $this->assertSame( $expected_resolved, $r['resolved_url'] );
        $this->assertSame( 1, $calls );
        $this->assertCount( 1, $writes );
        [ $key, $value, $ttl ] = $writes[0];
        $this->assertSame( $this->url_key( 'https://host.com/pricing/' ), $key );
        $this->assertSame( [ 'resolved_url' => $expected_resolved, 'resolution_source' => $expected_source ], $value );
        $this->assertSame( 2 * HOUR_IN_SECONDS, $ttl, "state '$expected_source' is definitive ⇒ 2 h" );
    }

    public function definitive_resolution_states(): array {
        return [
            'redirect_final — same-site 301'      => [ 'https://host.com/plans/pricing/', 'redirect_final', 'https://host.com/plans/pricing/' ],
            'none — origin reported no redirect'  => [ null, 'none', 'https://host.com/pricing/' ],
            'none — final URL equals the request' => [ 'https://host.com/pricing/', 'none', 'https://host.com/pricing/' ],
            'cross_domain_reject — off-site 301'  => [ 'https://partner.example/x', 'cross_domain_reject', 'https://host.com/pricing/' ],
        ];
    }

    /**
     * AC-15, second half — the entry is honoured inside the window and a probe fires
     * again once it expires. Both halves in one run, so "0 warm / 1 expired" is pinned
     * as a sequence rather than as two unrelated assertions.
     */
    public function test_definitive_entry_honoured_warm_then_reprobed_once_expired(): void {
        $host   = $this->host_entry();
        $entry  = [ 'resolved_url' => 'https://host.com/plans/pricing/', 'resolution_source' => 'redirect_final' ];
        $warm   = true;
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html' ],
            self::PROBE_BODY,
            'https://host.com/plans/pricing/',
            $calls,
            $writes,
            function ( $key ) use ( $host, $entry, &$warm ) {
                if ( $key === $this->host_key() ) {
                    return $host;
                }
                if ( $key === $this->url_key( 'https://host.com/pricing/' ) ) {
                    return $warm ? $entry : false;
                }
                return false;
            }
        );

        $r1 = PluginDetector::probe_target_stack( 'https://host.com/pricing/' );
        $this->assertSame( 'https://host.com/plans/pricing/', $r1['resolved_url'] );
        $this->assertSame( 0, $calls, 'inside the 2 h window the entry answers for free' );
        $this->assertSame( [], $writes );

        $warm = false; // TTL elapsed — the transient is gone.

        $r2 = PluginDetector::probe_target_stack( 'https://host.com/pricing/' );
        $this->assertSame( 'https://host.com/plans/pricing/', $r2['resolved_url'] );
        $this->assertSame( 1, $calls, 'once expired, exactly one probe re-establishes the resolution' );
        $this->assertCount( 1, $writes );
        $this->assertSame( 2 * HOUR_IN_SECONDS, $writes[0][2] );
    }

    // =========================================================================
    // AC-13 — the hit path is OBSERVABLE. debug_log_resolution() is
    // resolution_source's only reader in the codebase and it ran on the cache-MISS
    // path alone, so 'not_probed' — a state that exists ONLY on a hit-path return —
    // could never appear in a log. A state nobody can see is a state nobody can
    // debug, which is the whole point of the spec §6 telemetry.
    //
    // ⚠️ P17 — these drive the REAL probe_target_stack() and assert on the bytes the
    // REAL error_log() wrote, captured by pointing the error_log ini at a temp file.
    // Neither a source-text pin nor an assertion against a mocked logger would redden
    // if the call were deleted, retyped, or parked behind the wrong branch.
    // =========================================================================

    /**
     * Run $fn with the debug gate open and error_log() redirected to a temp file, and
     * return everything error_log() actually wrote. The ini setting is restored and the
     * temp file removed even if $fn throws.
     *
     * CU_SCANNER_DEBUG can never be un-defined once set, and ScannerAjaxTest's AC-DG-1
     * asserts the gate is OFF in the test env, so every caller of this helper must run
     * in a separate process (@runInSeparateProcess + @preserveGlobalState disabled).
     */
    private function capture_resolution_log( callable $fn ): string {
        defined( 'CU_SCANNER_DEBUG' ) || define( 'CU_SCANNER_DEBUG', true );
        WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $data ) => json_encode( $data ) );

        $tmp      = tempnam( sys_get_temp_dir(), 'cu-resolution-log' );
        $previous = (string) ini_get( 'error_log' );
        ini_set( 'error_log', $tmp );
        try {
            $fn();
            return (string) file_get_contents( $tmp );
        } finally {
            ini_set( 'error_log', $previous );
            unlink( $tmp );
        }
    }

    /**
     * AC-13 — a step-3 cross-path hit emits the resolution line, tagged 'not_probed'.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_hit_path_emits_resolution_debug_line(): void {
        $host   = $this->host_entry( [ 'bypass_suffixes' => [], 'detected' => [] ] );
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html' ],
            self::PROBE_BODY,
            'https://host.com/plans/pricing/',
            $calls,
            $writes,
            fn( $key ) => $key === $this->host_key() ? $host : false
        );

        $logged = $this->capture_resolution_log(
            fn() => PluginDetector::probe_target_stack( 'https://host.com/pricing/' )
        );

        $this->assertStringContainsString( 'cu_scanner.resolution', $logged,
            'the hit path must emit the resolution line — its only reader used to run on the miss path alone' );
        $this->assertStringContainsString( '"resolution_source":"not_probed"', $logged,
            "step 3's state has to survive into the log verbatim, or the telemetry names a state nobody can find" );
        $this->assertStringContainsString( '"submitted_url":"https:\/\/host.com\/pricing\/"', $logged,
            'the line describes the URL actually asked for' );
        $this->assertStringContainsString( '"cache_hit":true', $logged,
            'a hit-path line that claims cache_hit:false would send the reader hunting for an HTTP request that never happened' );
        $this->assertStringContainsString( '"redirect_final":null', $logged,
            "step 3 nulls the cached redirect_final; logging \$url1's hop on \$url2's line is the misattribution §4.2 removed" );
        $this->assertSame( 1, substr_count( $logged, 'cu_scanner.resolution' ),
            'exactly ONE line per call — the hit path returns before the miss-path logger, so a second line means double emission' );
        $this->assertSame( 0, $calls, 'step 3 stays free — observing it must not cost a request' );
    }

    /**
     * AC-13 — the miss path still logs, and still exactly once. The hit-path call is
     * an ADDITION on a return the miss-path logger can never be reached from; if the
     * two ever ran for one call, every cache miss would emit a duplicate line and the
     * log would double-count resolutions.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_miss_path_logs_exactly_once(): void {
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html' ],
            self::PROBE_BODY,
            'https://host.com/plans/pricing/',
            $calls,
            $writes
        );

        $logged = $this->capture_resolution_log(
            fn() => PluginDetector::probe_target_stack( 'https://host.com/pricing/' )
        );

        $this->assertSame( 1, substr_count( $logged, 'cu_scanner.resolution' ),
            'one call, one line — the miss path logs, the hit path is not also reached' );
        $this->assertStringContainsString( '"resolution_source":"redirect_final"', $logged );
        $this->assertStringContainsString( '"cache_hit":false', $logged );
    }

    /**
     * AC-13 — resolution_source has FOUR observable states, not three, and a fifth
     * label reaches this line from the per-URL store. All of them have to arrive
     * intact: attach_resolution() mints 'redirect_final' / 'none' / 'cross_domain_reject',
     * the §4.2 ladder's step 3 adds 'not_probed', and persist_url_resolution() mints
     * 'probe_failed'. The rows below pin each state against the path that produces it;
     * for how to READ the field — including the two labels one failed probe can carry —
     * see debug_log_resolution()'s docblock, which is the single place that is explained.
     *
     * @dataProvider hit_path_resolution_states
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_hit_path_logs_every_resolution_state(
        array $host_overrides,
        ?array $per_url_entry,
        ?string $final_url,
        int $status,
        string $expected_source,
        int $expected_calls
    ): void {
        $host   = $this->host_entry( $host_overrides );
        $calls  = 0;
        $writes = [];
        $this->stub_probe_response_counting(
            [ 'content-type' => 'text/html' ],
            200 === $status ? self::PROBE_BODY : '',
            $final_url,
            $calls,
            $writes,
            function ( $key ) use ( $host, $per_url_entry ) {
                if ( $key === $this->host_key() ) {
                    return $host;
                }
                return ( null !== $per_url_entry && $key === $this->url_key( 'https://host.com/pricing/' ) )
                    ? $per_url_entry
                    : false;
            },
            $status
        );

        $logged = $this->capture_resolution_log(
            fn() => PluginDetector::probe_target_stack( 'https://host.com/pricing/' )
        );

        $this->assertSame( 1, substr_count( $logged, 'cu_scanner.resolution' ),
            'every hit-path branch emits exactly one line — no branch is silent, none logs twice' );
        $this->assertStringContainsString( '"resolution_source":"' . $expected_source . '"', $logged,
            "state '$expected_source' must reach the log under its own name" );
        $this->assertStringContainsString( '"cache_hit":true', $logged );
        $this->assertSame( $expected_calls, $calls,
            'logging is free — it must not change how many requests the ladder spends' );
    }

    public function hit_path_resolution_states(): array {
        $to_plans = 'https://host.com/plans/pricing/';
        return [
            // Step 3 — suffix-less host, nothing probed, nothing persisted.
            'not_probed — step 3, suffix-less host' =>
                [ [ 'bypass_suffixes' => [], 'detected' => [] ], null, $to_plans, 200, 'not_probed', 0 ],

            // Step 1 — served from a warm per-URL entry, zero HTTP. 'probe_failed' can
            // ONLY reach a consumer this way: persist_url_resolution() is its only writer.
            'redirect_final — step 1, warm entry' =>
                [ [], [ 'resolved_url' => $to_plans, 'resolution_source' => 'redirect_final' ], $to_plans, 200, 'redirect_final', 0 ],
            'probe_failed — step 1, warm entry from an errored probe' =>
                [ [], [ 'resolved_url' => 'https://host.com/pricing/', 'resolution_source' => 'probe_failed' ], $to_plans, 200, 'probe_failed', 0 ],

            // Step 2 — one fresh probe, every outcome it can produce.
            'redirect_final — step 2, same-site 301'   => [ [], null, $to_plans, 200, 'redirect_final', 1 ],
            'none — step 2, origin reported no hop'    => [ [], null, null, 200, 'none', 1 ],
            'cross_domain_reject — step 2, off-site'   => [ [], null, 'https://partner.example/x', 200, 'cross_domain_reject', 1 ],
            'probe_failed — step 2, the probe errored' => [ [], null, null, 404, 'probe_failed', 1 ],

            // Same-path hit — never enters the ladder, but must still be observable.
            'none — same-path hit, cached resolution' => [
                [
                    'submitted_url'     => 'https://host.com/pricing/',
                    'resolved_url'      => 'https://host.com/pricing/',
                    'resolution_source' => 'none',
                    'redirect_final'    => 'https://host.com/pricing/',
                ],
                null, $to_plans, 200, 'none', 0,
            ],
        ];
    }
}

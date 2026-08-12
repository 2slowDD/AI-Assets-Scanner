<?php
// tests/PluginDetectorUrlResolutionKeyTest.php
namespace CUScanner\Tests;

use CUScanner\Scanner\PluginDetector;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Task 1 — per-URL resolution cache key builder + TTL tiers (spec §4.1).
 *
 * Covers: scheme/host case + explicit default port normalisation, path/query
 * identity (trailing slash and query string are part of the resource, by
 * design), fragment stripping, and TTL tiering by resolution_source.
 */
class PluginDetectorUrlResolutionKeyTest extends TestCase {

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

    public function test_key_normalises_scheme_host_case_and_explicit_port(): void {
        $this->stub_wp_parse_url();
        $a = PluginDetector::__test_build_url_resolution_key( 'HTTPS://Host.com/pricing/' );
        $b = PluginDetector::__test_build_url_resolution_key( 'https://host.com:443/pricing/' );
        $this->assertSame( $a, $b, 'case + explicit default port must not change the key' );
        $this->assertStringStartsWith( 'cu_scanner_url_res_v7_', $a );
    }
    public function test_key_distinguishes_paths_and_preserves_query(): void {
        $this->stub_wp_parse_url();
        $k = fn( string $u ) => PluginDetector::__test_build_url_resolution_key( $u );
        $this->assertNotSame( $k('https://host.com/pricing'), $k('https://host.com/pricing/'), 'trailing slash is a different resource' );
        $this->assertNotSame( $k('https://host.com/p'), $k('https://host.com/p?x=1'), 'query is part of identity' );
        $this->assertSame( $k('https://host.com/p?x=1#frag'), $k('https://host.com/p?x=1'), 'fragment stripped' );
    }

    /**
     * Spec §7.1 mutation gate, Finding 1. test_key_normalises_scheme_host_case_and_explicit_port
     * above proves only that an EXPLICIT DEFAULT port collapses onto the default — it cannot
     * reach the direction that matters, because dropping ":{$port}" from the normalisation
     * entirely leaves both of its sides equal. That mutation ran green against all 845 tests
     * while colliding https://host.com/x with https://host.com:8443/x onto one resolution
     * entry, which is this spec's own cross-contamination defect one axis over.
     *
     * The scheme-dependent default is a bare literal and every other fixture in this file is
     * https, so the '80' branch of the ternary is pinned here too — otherwise a typo in it
     * reddens nothing either.
     */
    public function test_key_port_is_part_of_identity_and_defaults_per_scheme(): void {
        $this->stub_wp_parse_url();
        $k = fn( string $u ) => PluginDetector::__test_build_url_resolution_key( $u );
        $this->assertNotSame( $k('https://host.com/x'), $k('https://host.com:8443/x'), 'a non-default port is a different origin' );
        $this->assertSame( $k('http://host.com/x'), $k('http://host.com:80/x'), 'http defaults to port 80, not 443' );
    }

    public function test_ttl_tiers(): void {
        foreach ( [ 'redirect_final', 'none', 'cross_domain_reject' ] as $src ) {
            $this->assertSame( 2 * HOUR_IN_SECONDS, PluginDetector::__test_url_resolution_ttl( $src ), $src );
        }
        $this->assertSame( 15 * MINUTE_IN_SECONDS, PluginDetector::__test_url_resolution_ttl( 'probe_failed' ) );
    }
}

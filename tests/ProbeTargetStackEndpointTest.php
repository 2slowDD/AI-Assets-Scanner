<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * FU-NEW-2 Phase 4 — cu_scanner_probe_target_stack AJAX endpoint tests.
 * Spec §6.1 + §6.1.1 + AC-N2-Auth + AC-N2-SSRF.
 */
class ProbeTargetStackEndpointTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * AC-N2-Auth — non-admin user denied (current_user_can('manage_options') = false → 403).
     */
    public function test_endpoint_denies_non_admin() {
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( false );

        // Expect wp_send_json to be called with 403 status.
        $sent = null;
        WP_Mock::userFunction( 'wp_send_json' )->andReturnUsing( function ( $data, $status = null ) use ( &$sent ) {
            $sent = [ 'data' => $data, 'status' => $status ];
            throw new \Exception( 'wp_send_json_called' );
        } );

        try {
            ( new ScannerAjax() )->probe_target_stack();
        } catch ( \Exception $e ) {
            // wp_send_json throws to short-circuit; tests catch.
        }

        $this->assertNotNull( $sent );
        $this->assertSame( 403, $sent['status'] );
        $this->assertSame( 'permission_denied', $sent['data']['error'] ?? null );
    }

    /**
     * AC-N2-Auth — missing nonce → 403.
     */
    public function test_endpoint_denies_missing_nonce() {
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( false );

        $sent = null;
        WP_Mock::userFunction( 'wp_send_json' )->andReturnUsing( function ( $data, $status = null ) use ( &$sent ) {
            $sent = [ 'data' => $data, 'status' => $status ];
            throw new \Exception( 'wp_send_json_called' );
        } );

        try {
            ( new ScannerAjax() )->probe_target_stack();
        } catch ( \Exception $e ) {}

        $this->assertNotNull( $sent );
        $this->assertSame( 403, $sent['status'] );
        $this->assertSame( 'nonce_invalid', $sent['data']['error'] ?? null );
    }

    /**
     * AC-N2-SSRF (iii) — response field whitelist enforced via strip_to_whitelist test seam.
     */
    public function test_endpoint_strips_response_fields_to_whitelist() {
        $raw_result = [
            'host'             => 'example.com',
            'outcome'          => 'class_a_clean',
            'detected'         => [],
            'bypass_suffixes'  => [],
            'is_wordpress'     => true,
            'probed_url_1'     => 'https://example.com/',
            'probe_duration_ms'=> 1234,
            'cache_hit'        => false,
            // Disallowed fields:
            'body'             => '<html>raw body content</html>',
            'raw_headers'      => [ 'x-secret' => 'value' ],
            'set_cookie'       => 'session=abc',
        ];
        $stripped = ScannerAjax::__test_strip_to_whitelist( $raw_result );

        $this->assertArrayNotHasKey( 'body',        $stripped );
        $this->assertArrayNotHasKey( 'raw_headers', $stripped );
        $this->assertArrayNotHasKey( 'set_cookie',  $stripped );
        $this->assertArrayHasKey(    'host',        $stripped );
        $this->assertArrayHasKey(    'outcome',     $stripped );
        $this->assertArrayHasKey(    'detected',    $stripped );
    }

    /**
     * Per-host grouping behavior — same host (incl. www.) maps to single bucket.
     * Spec §6.1 — server-side flow groups URLs by external host before probing.
     */
    public function test_group_urls_by_host_groups_per_host() {
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );

        $urls = [
            'https://pinadventures.com/',
            'https://pinadventures.com/shop/',
            'https://other.com/',
            'https://www.pinadventures.com/blog/',  // www prefix stripped, same host
        ];
        $grouped = ScannerAjax::__test_group_urls_by_host( $urls );

        $this->assertCount( 2, $grouped );
        $this->assertArrayHasKey( 'pinadventures.com', $grouped );
        $this->assertArrayHasKey( 'other.com', $grouped );
        $this->assertCount( 3, $grouped['pinadventures.com'] );
        $this->assertCount( 1, $grouped['other.com'] );
    }
}

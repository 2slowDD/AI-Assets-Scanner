<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * FU-NEW-2 Phase 5 — submit_job payload construction per spec §4.2 rule.
 *
 * Covers Tasks 5.1 + 5.2 (per-URL bypass_suffixes), 5.3 + 5.4 (target_stack_summary),
 * and 5.5 (cu_scanner_target_bypass_missing event on fallback).
 */
class SubmitJobPayloadTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        // wp_parse_url passthrough — most tests need real URL parsing.
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( $url, $component = null ) {
            $parts = parse_url( $url );
            if ( $component === null ) return $parts;
            $map = [ PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme', PHP_URL_PATH => 'path', PHP_URL_QUERY => 'query' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );

        // sanitize_text_field passthrough.
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function ( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; } );
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * AC-N2-1 — external URLs use target-detected suffixes from probe response;
     * internal URLs use host-detected suffixes (today's behavior).
     */
    public function test_external_uses_target_internal_uses_host() {
        WP_Mock::userFunction( 'do_action' )->andReturn( null );

        $selected_urls = [
            'https://wpservice.pro/page1/',
            'https://pinadventures.com/',
            'https://pinadventures.com/shop/',
        ];
        $host_bypass = [ 'nowprocket', 'perfmattersoff' ];
        $target_bypass_per_url = [
            'https://pinadventures.com/'      => [],
            'https://pinadventures.com/shop/' => [],
        ];
        $home_url = 'https://wpservice.pro';

        $pages = ScannerAjax::__test_build_pages_array(
            $selected_urls, $host_bypass, $target_bypass_per_url, $home_url
        );

        $this->assertCount( 3, $pages );
        $this->assertSame( [ 'nowprocket', 'perfmattersoff' ], $pages[0]['bypass_suffixes'] );
        $this->assertSame( [], $pages[1]['bypass_suffixes'] );
        $this->assertSame( [], $pages[2]['bypass_suffixes'] );
        // Verify URL preserved.
        $this->assertSame( 'https://wpservice.pro/page1/',      $pages[0]['url'] );
        $this->assertSame( 'https://pinadventures.com/',         $pages[1]['url'] );
        $this->assertSame( 'https://pinadventures.com/shop/',    $pages[2]['url'] );
    }

    /**
     * AC-N2-12 — external URL missing from target_bypass map defaults to []
     * (NOT host-leaked), AND fires cu_scanner_target_bypass_missing action.
     */
    public function test_external_missing_from_map_defaults_to_empty() {
        WP_Mock::userFunction( 'do_action' )->andReturn( null );

        $selected_urls = [
            'https://wpservice.pro/internal/',
            'https://strange-external.com/',
        ];
        $host_bypass = [ 'nowprocket' ];
        $target_bypass_per_url = [];
        $home_url = 'https://wpservice.pro';

        $pages = ScannerAjax::__test_build_pages_array(
            $selected_urls, $host_bypass, $target_bypass_per_url, $home_url
        );

        $this->assertSame( [ 'nowprocket' ], $pages[0]['bypass_suffixes'] );
        $this->assertSame( [], $pages[1]['bypass_suffixes'],
            'External URL missing from map MUST default to [] (NOT host-leaked)' );
    }

    /**
     * AC-N2-12 — fallback fires action hook cu_scanner_target_bypass_missing
     * with payload { url, host } for the missing external URL.
     *
     * WP_Mock::expectAction() asserts the action is fired with the exact args.
     * Internal URLs MUST NOT trigger the fallback (so we only expect one call
     * for the strange.com external URL, not for the wpservice.pro internal one).
     */
    public function test_target_bypass_missing_event_fires_on_fallback() {
        WP_Mock::expectAction(
            'cu_scanner_target_bypass_missing',
            [ 'url' => 'https://strange.com/', 'host' => 'strange.com' ]
        );

        ScannerAjax::__test_build_pages_array(
            [ 'https://wpservice.pro/internal/', 'https://strange.com/' ],
            [ 'nowprocket' ],
            [],
            'https://wpservice.pro'
        );

        $this->assertConditionsMet();
    }

    /**
     * AC-RC-8a — build_pages_array threads the resolved scan URL through `url` while
     * preserving the original operator-submitted URL in `submitted_url`.
     *
     * The submitted_url map is keyed by the RESOLVED URL (build_pages_array iterates the
     * resolved $selected_urls), exactly as submit_job zips urls[]→submitted_urls[] by index.
     */
    public function test_build_pages_array_carries_submitted_url() {
        WP_Mock::userFunction( 'do_action' )->andReturn( null );

        // urls[] is already the resolved URL; submitted_urls[] is the original.
        $resolved  = 'https://www.cloudways.com/en';
        $submitted = 'https://cloudways.com';

        $pages = ScannerAjax::__test_build_pages_array(
            [ $resolved ],                       // selected (resolved) URLs
            [ 'nowprocket' ],                    // host bypass
            [],                                  // target bypass map
            'https://wpservice.pro',             // home url (so the URL is external)
            [],                                  // et_set
            [ $resolved => $submitted ]          // AC-RC-8a resolved→submitted map
        );

        $this->assertCount( 1, $pages );
        $this->assertSame( $resolved,  $pages[0]['url'],
            'Page url MUST be the resolved (post-redirect) scan URL' );
        $this->assertSame( $submitted, $pages[0]['submitted_url'],
            'Page submitted_url MUST be the original operator-submitted URL' );
    }

    /**
     * AC-RC-8a (identity) — when no resolution map entry exists for a URL (probe found
     * no redirect), submitted_url falls back to the URL itself.
     */
    public function test_build_pages_array_submitted_url_defaults_to_identity() {
        WP_Mock::userFunction( 'do_action' )->andReturn( null );

        $pages = ScannerAjax::__test_build_pages_array(
            [ 'https://wpservice.pro/page1/' ],
            [ 'nowprocket' ],
            [],
            'https://wpservice.pro',
            [],
            []   // empty resolved→submitted map
        );

        $this->assertSame( 'https://wpservice.pro/page1/', $pages[0]['url'] );
        $this->assertSame( 'https://wpservice.pro/page1/', $pages[0]['submitted_url'],
            'submitted_url defaults to the URL itself when no resolution map entry exists' );
    }

    /**
     * AC-RC-8b — submitted_url survives reshape_page_specs (the hardcoded-key seam).
     *
     * Regression-locks the carry-through documented in the FU-AAS-EXTRA-TIME scar:
     * a key omitted from reshape's explicit array literal is silently dropped.
     * Asserts both the carry path (submitted_url present in spec) and the fallback
     * path (submitted_url absent → ?? $spec['url']).
     */
    public function test_reshape_page_specs_carries_submitted_url() {
        $identity_build_scan_url = static fn( string $url, array $suffixes ): string => $url;
        $token = 'test-bypass-token';

        // --- carry path: submitted_url present in spec ---
        $resolved  = 'https://www.cloudways.com/en';
        $submitted = 'https://cloudways.com';

        $result = ScannerAjax::__test_reshape_page_specs(
            [
                [
                    'url'             => $resolved,
                    'bypass_suffixes' => [ 'nowprocket' ],
                    'extra_time'      => false,
                    'submitted_url'   => $submitted,
                ],
            ],
            $identity_build_scan_url,
            $token
        );

        $this->assertCount( 1, $result );
        $this->assertSame( $resolved,  $result[0]['url'],
            'url must be the resolved scan URL after reshape' );
        $this->assertSame( $submitted, $result[0]['submitted_url'],
            'submitted_url must survive reshape_page_specs carry-through' );

        // --- fallback path: no submitted_url in spec → defaults to url ---
        $url_only = 'https://wpservice.pro/page1/';

        $result2 = ScannerAjax::__test_reshape_page_specs(
            [
                [
                    'url'             => $url_only,
                    'bypass_suffixes' => [],
                    // submitted_url intentionally absent — tests the ?? $spec['url'] path
                ],
            ],
            $identity_build_scan_url,
            $token
        );

        $this->assertCount( 1, $result2 );
        $this->assertSame( $url_only, $result2[0]['submitted_url'],
            'submitted_url must fall back to url when absent from spec (?? $spec[\'url\'] path)' );
    }

    /**
     * AC-N2-10 — target_stack_summary blob is captured from $_POST and forwarded to SaaS.
     * Tests the helper that captures + sanitizes the blob before SaaS post.
     */
    public function test_capture_target_stack_summary_from_post() {
        $post_data = [
            [ 'host' => 'pinadventures.com', 'detected' => [ 'Breeze (body)' ],
              'outcome' => 'class_bc_only', 'cache_hit' => false ],
        ];

        $captured = ScannerAjax::__test_capture_target_stack_summary( $post_data );

        $this->assertNotNull( $captured );
        $this->assertCount( 1, $captured );
        $this->assertSame( 'pinadventures.com', $captured[0]['host'] );
        $this->assertSame( 'class_bc_only', $captured[0]['outcome'] );
    }

    /**
     * Empty / missing target_stack_summary returns null (signals SaaS shouldn't include the field).
     */
    public function test_capture_target_stack_summary_empty_returns_null() {
        $this->assertNull( ScannerAjax::__test_capture_target_stack_summary( null ) );
        $this->assertNull( ScannerAjax::__test_capture_target_stack_summary( [] ) );
    }
}

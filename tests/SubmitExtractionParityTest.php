<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Phase O — outbox parity-by-construction (AC-O-8).
 *
 * Proves the extraction boundary of build_submit_payload(): the method reads its
 * inputs from the $intent array (NOT $_POST), accepts an INJECTED fixed bypass
 * token (so a replay can reproduce the exact same token), and returns a worker
 * payload that does NOT carry job_token (the caller late-binds it) but DOES carry
 * the reshaped pages[] array. This is the seam both submit_job() (interactive) and
 * the future Outbox::dispatch() (replay) call, so a replayed scan is identical to
 * an interactive one by construction.
 *
 * Scope: only the extraction boundary — no job_token, pages present, injected
 * token honored — not the payload internals (those are covered by the existing
 * ScannerAjaxTest build_pages_array / reshape_page_specs cases).
 */
class SubmitExtractionParityTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        // Plugin constant the payload builder embeds; not defined by bootstrap.php.
        if ( ! defined( 'CU_SCANNER_WPSERVICE_BASE' ) ) {
            define( 'CU_SCANNER_WPSERVICE_BASE', 'https://wpservice.pro' );
        }
    }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    /**
     * Stub the WP functions build_submit_payload() reaches through:
     * detection (is_plugin_active/get_option), Settings getters (get_option),
     * URL assembly (is_ssl/home_url/wp_parse_url/add_query_arg/set_url_scheme/
     * sanitize_url/esc_url_raw). With no active plugins the detector returns an
     * empty typed map (no Class C), which is the simplest faithful path.
     */
    private function stubBuilderWpFns(): void {
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->andReturnUsing(
            function ( string $name, $default = '' ) {
                // get_scanner_secret() returns the stored value when non-empty,
                // avoiding random_bytes(); everything else takes its default.
                if ( 'cu_scanner_secret' === $name ) {
                    return 'test-scanner-secret';
                }
                return $default;
            }
        );
        WP_Mock::userFunction( 'home_url' )->andReturn( 'https://example.com' );
        WP_Mock::userFunction( 'is_ssl' )->andReturn( true );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
            function ( string $url, ?int $component = null ) {
                $parts = parse_url( $url );
                if ( null === $component ) {
                    return $parts;
                }
                $map = [
                    PHP_URL_SCHEME => 'scheme',
                    PHP_URL_HOST   => 'host',
                    PHP_URL_PATH   => 'path',
                    PHP_URL_QUERY  => 'query',
                ];
                return $parts[ $map[ $component ] ?? '' ] ?? null;
            }
        );
        WP_Mock::userFunction( 'sanitize_url' )->andReturnUsing( fn( $u ) => $u );
        WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( fn( $u ) => $u );
        WP_Mock::userFunction( 'set_url_scheme' )->andReturnUsing(
            fn( string $url, $scheme = null ) => $url
        );
        WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
            function ( $args, $url = '' ) {
                // Same-host bypass-param append; with no detected params $args is
                // empty so the URL is returned unchanged.
                if ( empty( $args ) ) {
                    return $url;
                }
                $sep = ( strpos( (string) $url, '?' ) === false ) ? '?' : '&';
                return $url . $sep . http_build_query( $args );
            }
        );
    }

    public function test_build_submit_payload_omits_job_token_and_uses_injected_token(): void {
        $this->stubBuilderWpFns();

        $intent = [
            'urls'                  => [ 'https://example.com/a', 'https://example.com/b' ],
            'submitted_urls'        => [ 'https://example.com/a', 'https://example.com/b' ],
            'extra_time_urls'       => [],
            'extra_time_count'      => 0,
            'page_count'            => 2,
            'target_bypass_per_url' => [],
            'target_stack_summary'  => null,
        ];

        [ $payload, $detector_typed, $token ] =
            ( new ScannerAjax() )->build_submit_payload( $intent, 'FIXED_BYPASS_TOKEN' );

        // 1. The returned payload MUST NOT carry job_token — the caller late-binds it.
        $this->assertArrayNotHasKey(
            'job_token',
            $payload,
            'build_submit_payload must NOT bind job_token; the caller late-binds it'
        );

        // 2. The reshaped pages[] array is present (and one row per intent URL).
        $this->assertArrayHasKey( 'pages', $payload, 'payload must carry the reshaped pages array' );
        $this->assertCount( 2, $payload['pages'] );

        // 3. The INJECTED fixed token is used verbatim (no fresh create_token()).
        $this->assertSame( 'FIXED_BYPASS_TOKEN', $token, 'injected bypass token must be returned' );
        $this->assertSame(
            'FIXED_BYPASS_TOKEN',
            $payload['pages'][0]['bypass_token'],
            'every page must carry the injected bypass token'
        );

        // 4. The detection result is returned for the consent gate + side-effects.
        $this->assertIsArray( $detector_typed );

        $this->assertConditionsMet();
    }
}

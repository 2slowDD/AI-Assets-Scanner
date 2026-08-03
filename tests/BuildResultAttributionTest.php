<?php
// tests/BuildResultAttributionTest.php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * AC-1 — `rate_limit_attribution` must survive the whole trip from the worker's
 * status response to the payload the browser receives.
 *
 * P17: this drives the REAL public entry point, `do_build_result()`, with only the
 * Railway HTTP response stubbed. A source-text pin on the payload array
 * (`assertStringContainsString( 'rate_limit_attribution', file_get_contents(...) )`)
 * does NOT satisfy AC-1 — it passes with a wrong value, a typo'd key, or the line
 * sitting in a comment. Everything between get_status() and the returned array is
 * the real code path.
 */
class BuildResultAttributionTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * One rate-limited page, shaped the way the worker actually emits it: the
	 * attribution rides on the per-device broken_devices entry, not on the page.
	 *
	 * @param string|null $attribution Null omits the key entirely (pre-deploy worker).
	 */
	private function rate_limited_page( string $url, $attribution ): array {
		$broken = [ 'device' => 'desktop', 'reason' => 'tier1_http_rate_limit' ];
		if ( null !== $attribution ) {
			$broken['attribution'] = $attribution;
		}
		return [
			'url'             => $url,
			'status'          => 'done',
			'assets'          => [
				[
					'handle'  => 'h-' . md5( $url ),
					'type'    => 'style',
					'desktop' => [ 'loaded' => false, 'coverage' => 0.0 ],
					'mobile'  => [ 'loaded' => false, 'coverage' => 0.0 ],
				],
			],
			'broken_devices'  => [ $broken ],
		];
	}

	/**
	 * Stubs every WP function do_build_result() reaches, then returns the real
	 * payload it produces for the given worker pages.
	 */
	private function build_payload_for( array $pages ): array {
		WP_Mock::userFunction( 'wp_parse_url' )
			->andReturnUsing( fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		WP_Mock::userFunction( '__' )->andReturnUsing( fn( $t, $d = null ) => $t );

		// RailwayClient::get_status() — the only external boundary.
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [] );
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [
			'status'    => 'complete',
			'total'     => count( $pages ),
			'completed' => count( $pages ),
			'pages'     => $pages,
			'flags'     => [],
		] ) );

		// The Settings-backed options RailwayClient's host allowlist requires; every
		// other option falls through to its default.
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $k, $default = false ) {
				if ( 'cu_scanner_railway_url' === $k ) {
					return 'https://cu-scanner-railway-production.up.railway.app';
				}
				if ( 'cu_scanner_api_key' === $k ) {
					return 'api-key-123';
				}
				return $default;
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturn( true );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $d, $f = 0 ) => json_encode( $d, $f ) );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value = null ) => $value );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
		WP_Mock::userFunction( 'do_action' )->andReturn( null );
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );

		return ( new ScannerAjax() )->do_build_result( 'job-xyz', 'tok-abc' );
	}

	public function test_payload_carries_cloudflare_attribution(): void {
		$payload = $this->build_payload_for( [
			$this->rate_limited_page( 'https://site.test/a/', 'cloudflare' ),
		] );

		$this->assertArrayHasKey( 'rate_limit_attribution', $payload );
		$this->assertSame( 'cloudflare', $payload['rate_limit_attribution'] );
	}

	public function test_payload_carries_host_attribution(): void {
		$payload = $this->build_payload_for( [
			$this->rate_limited_page( 'https://site.test/a/', 'host' ),
		] );

		$this->assertSame( 'host', $payload['rate_limit_attribution'] );
	}

	/**
	 * A worker that predates the attribution deploy omits the key entirely. The
	 * field must still be present on the payload and must be null, never the
	 * string 'unknown' — the aggregator is presence-keyed (R1), and a bogus
	 * 'unknown' would render the fallback copy on a scan nothing rate-limited.
	 */
	public function test_payload_field_is_null_when_the_worker_sends_no_attribution(): void {
		$payload = $this->build_payload_for( [
			$this->rate_limited_page( 'https://site.test/a/', null ),
		] );

		$this->assertArrayHasKey( 'rate_limit_attribution', $payload );
		$this->assertNull( $payload['rate_limit_attribution'] );
	}

	/**
	 * The aggregator ranks CDN-edge above host on a tie, so a mixed scan resolves
	 * to cloudflare. This is the case the banner copy deliberately does NOT deny
	 * the origin for.
	 */
	public function test_mixed_scan_resolves_to_the_higher_ranked_party(): void {
		$payload = $this->build_payload_for( [
			$this->rate_limited_page( 'https://site.test/a/', 'host' ),
			$this->rate_limited_page( 'https://site.test/b/', 'cloudflare' ),
		] );

		$this->assertSame( 'cloudflare', $payload['rate_limit_attribution'] );
	}
}

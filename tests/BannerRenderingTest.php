<?php
namespace CUScanner\Tests;

use AIAS_Broken_Banner;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Tests for AIAS_Broken_Banner rendering + dismissal logic.
 */
class BannerRenderingTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// render() — no blocked pages → empty string
	// -------------------------------------------------------------------------

	public function test_no_banner_when_no_blocked_pages(): void {
		$html = AIAS_Broken_Banner::render( [
			'scan_id'         => 'abc',
			'pages_blocked'   => [ 'desktop' => 0, 'mobile' => 0 ],
			'blocked_reasons' => [],
		] );

		$this->assertSame( '', $html );
	}

	// -------------------------------------------------------------------------
	// render() — desktop blocked with cf reason → banner with key strings
	// -------------------------------------------------------------------------

	public function test_desktop_blocked_banner_with_cf_reason(): void {
		// T0-C fix: this test inlined a PARTIAL mock set (no admin_url/esc_url/__), so it
		// died with "Call to undefined function admin_url()" the moment action_clause()
		// built the settings anchor — a pre-existing ERROR on origin/main (1.7.86b).
		// stub_render_helpers() already existed with the complete set; this test just
		// predated it. Test-harness gap only, no production behaviour involved.
		$this->stub_render_helpers();

		$html = AIAS_Broken_Banner::render( [
			'scan_id'         => 'abc',
			'pages_blocked'   => [ 'desktop' => 5, 'mobile' => 0 ],
			'blocked_reasons' => [ 'tier2_cf_challenge' => 5 ],
			'total_pages'     => 10,
		] );

		$this->assertStringContainsString( 'Desktop scanner blocked on 5 of', $html );
		$this->assertStringContainsString( 'Cloudflare', $html );
		$this->assertStringContainsString( 'temporarily disable bot protection', $html );
	}

	/** @param mixed $attribution */
	private function rate_payload( $attribution ): array {
		return [
			'scan_id'                => 'rate-scan',
			'pages_blocked'          => [ 'desktop' => 1, 'mobile' => 0 ],
			'blocked_reasons'        => [ 'tier1_http_rate_limit' => 1 ],
			'total_pages'            => 3,
			'rate_limit_attribution' => $attribution,
		];
	}

	// -------------------------------------------------------------------------
	// T0-C — normalize_attribution(): allowlist, not sanitizer
	// -------------------------------------------------------------------------

	public function test_normalize_attribution_allowlists_known_values(): void {
		foreach ( AIAS_Broken_Banner::ATTRIBUTION_ALLOWED as $member ) {
			$this->assertSame( $member, AIAS_Broken_Banner::normalize_attribution( $member ) );
		}
	}

	public function test_normalize_attribution_rejects_everything_else(): void {
		foreach ( [ '', null, 'garbage', '<script>alert(1)</script>', 'HOST', 0, [], true ] as $bad ) {
			$this->assertSame( 'unknown', AIAS_Broken_Banner::normalize_attribution( $bad ) );
		}
	}

	// -------------------------------------------------------------------------
	// T0-C — the rate branch names the party that actually throttled the scan
	// -------------------------------------------------------------------------

	public function test_rate_banner_names_cloudflare_and_keeps_link(): void {
		$this->stub_render_helpers();

		$html = AIAS_Broken_Banner::render( $this->rate_payload( 'cloudflare' ) );

		$this->assertStringContainsString( 'Cloudflare rate-limited the scan', $html );
		$this->assertStringContainsString( 'cu-cloudflare-waf-bypass', $html );
		$this->assertStringNotContainsString( 'Your server rate-limited', $html );
	}

	public function test_rate_banner_names_host_and_drops_the_cdn_link(): void {
		$this->stub_render_helpers();

		$html = AIAS_Broken_Banner::render( $this->rate_payload( 'host' ) );

		$this->assertStringContainsString( "Your host's server rate-limited the scan", $html );
		$this->assertStringContainsString( 'will not help here', $html );
		$this->assertStringNotContainsString( 'cu-cloudflare-waf-bypass', $html );
	}

	public function test_rate_banner_unknown_variant_is_the_default_for_everything_else(): void {
		// Stub once, outside the loop — re-registering the same WP_Mock userFunction
		// expectations on each iteration errors out.
		$this->stub_render_helpers();

		foreach ( [ 'akamai', 'imperva', 'waf', 'unknown', 'garbage', null ] as $attr ) {
			$label = null === $attr ? 'null' : (string) $attr;
			$html  = AIAS_Broken_Banner::render( $this->rate_payload( $attr ) );

			$this->assertStringContainsString( 'The scan was rate-limited', $html, "attr={$label}" );
			$this->assertStringContainsString( 'cu-cloudflare-waf-bypass', $html, "attr={$label}" );
			$this->assertStringNotContainsString( 'Cloudflare rate-limited the scan', $html, "attr={$label}" );
		}
	}

	public function test_non_rate_branch_is_identical_with_and_without_the_field(): void {
		$this->stub_render_helpers();

		$base = [
			'scan_id'         => 'err-scan',
			'pages_blocked'   => [ 'desktop' => 1, 'mobile' => 0 ],
			'blocked_reasons' => [ 'tier1_http_5xx' => 1 ],
			'total_pages'     => 3,
		];

		$without = AIAS_Broken_Banner::render( $base );
		$with    = AIAS_Broken_Banner::render( $base + [ 'rate_limit_attribution' => 'cloudflare' ] );

		$this->assertSame( $without, $with );
		$this->assertStringContainsString( 'returned an error', $without );
	}

	// -------------------------------------------------------------------------
	// render() — dismissed scan → empty string
	// -------------------------------------------------------------------------

	public function test_dismissed_banner_returns_empty_html(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( AIAS_Broken_Banner::OPTION_DISMISSALS, [] )
			->andReturn( [ 'abc' => true ] );

		$html = AIAS_Broken_Banner::render( [
			'scan_id'         => 'abc',
			'pages_blocked'   => [ 'desktop' => 5, 'mobile' => 0 ],
			'blocked_reasons' => [ 'tier2_cf_challenge' => 5 ],
		] );

		$this->assertSame( '', $html );
	}

	// -------------------------------------------------------------------------
	// on_submit_job() — wipes all dismissals
	// -------------------------------------------------------------------------

	public function test_submit_job_wipes_all_dismissals(): void {
		$called = false;
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( AIAS_Broken_Banner::OPTION_DISMISSALS, [], false )
			->andReturnUsing( function () use ( &$called ) { $called = true; return true; } );

		AIAS_Broken_Banner::on_submit_job();

		$this->assertTrue( $called, 'update_option must be called to wipe dismissals' );
	}

	// -------------------------------------------------------------------------
	// reason_copy() per-reason action clause — rate-limit gets cadence guidance,
	// not bot-protection guidance (the original copy was misleading for 429s).
	// -------------------------------------------------------------------------

	private function stub_render_helpers(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( AIAS_Broken_Banner::OPTION_DISMISSALS, [] )
			->andReturn( [] );
		WP_Mock::userFunction( 'esc_attr' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'esc_html__' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'esc_html_e' )->andReturnUsing( function ( $t ) { echo $t; } );
		WP_Mock::userFunction( 'esc_html' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnArg( 0 );
		WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'esc_url' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'admin_url' )->andReturnArg( 0 );
	}

	public function test_rate_limit_alone_uses_cadence_action_clause(): void {
		$this->stub_render_helpers();

		$html = AIAS_Broken_Banner::render( [
			'scan_id'         => 'abc',
			'pages_blocked'   => [ 'desktop' => 2, 'mobile' => 0 ],
			'blocked_reasons' => [ 'tier1_http_rate_limit' => 2 ],
			'total_pages'     => 5,
		] );

		$this->assertStringContainsString( 'rate-limited', $html );
		$this->assertStringContainsString( 'between scans', $html );
		$this->assertStringNotContainsString( 'bot protection denied', $html );
		// CDN-exemption solution + settings deep-link must appear on 429 banners.
		// T0-C: this payload carries no `rate_limit_attribution`, so it takes the UNKNOWN
		// variant. The old assertion pinned "rate-limit exemption" — copy that promised a
		// CDN exemption would fix a limit we cannot attribute. It now hedges instead.
		$this->assertStringContainsString( 'allowlist the scanner', $html );
		$this->assertStringContainsString( 'cu-cloudflare-waf-bypass', $html );
		$this->assertStringNotContainsString( 'Your server rate-limited', $html );
	}

	public function test_server_error_alone_uses_retry_action_clause(): void {
		$this->stub_render_helpers();

		$html = AIAS_Broken_Banner::render( [
			'scan_id'         => 'abc',
			'pages_blocked'   => [ 'desktop' => 1, 'mobile' => 1 ],
			'blocked_reasons' => [ 'tier1_http_5xx' => 2 ],
			'total_pages'     => 5,
		] );

		$this->assertStringContainsString( 'didn\'t respond', $html );
		$this->assertStringNotContainsString( 'bot protection denied', $html );
	}

	public function test_mixed_reasons_falls_back_to_bot_protection_clause(): void {
		$this->stub_render_helpers();

		$html = AIAS_Broken_Banner::render( [
			'scan_id'         => 'abc',
			'pages_blocked'   => [ 'desktop' => 3, 'mobile' => 0 ],
			'blocked_reasons' => [
				'tier1_http_rate_limit' => 1,
				'tier2_cf_challenge'    => 2,
			],
			'total_pages'     => 5,
		] );

		// Mixed-category reasons fall back to the generic bot-protection clause
		// to avoid misleading single-cause guidance.
		$this->assertStringContainsString( 'bot protection denied', $html );
		// CDN-exemption solution also appears when rate is one of the reasons (mixed scan).
		$this->assertStringContainsString( 'rate-limit exemption', $html );
		$this->assertStringContainsString( 'cu-cloudflare-waf-bypass', $html );
	}

	// -------------------------------------------------------------------------
	// tier2_waf_challenge → 'firewall/WAF' phrase, bot category
	// -------------------------------------------------------------------------

	public function test_waf_challenge_shows_firewall_phrase_and_bot_action(): void {
		$this->stub_render_helpers();

		$html = AIAS_Broken_Banner::render( [
			'scan_id'         => 'waf-test',
			'pages_blocked'   => [ 'desktop' => 1, 'mobile' => 0 ],
			'blocked_reasons' => [ 'tier2_waf_challenge' => 1 ],
			'total_pages'     => 1,
		] );

		$this->assertStringContainsString( 'firewall/WAF', $html );
		$this->assertStringContainsString( 'temporarily disable bot protection', $html );
		$this->assertStringNotContainsString( 'rate-limited', $html );
	}

	// -------------------------------------------------------------------------
	// tier2_unknown_challenge → 'unidentified' phrase, bot category
	// -------------------------------------------------------------------------

	public function test_unknown_challenge_shows_unidentified_phrase_and_bot_action(): void {
		$this->stub_render_helpers();

		$html = AIAS_Broken_Banner::render( [
			'scan_id'         => 'unk-test',
			'pages_blocked'   => [ 'desktop' => 1, 'mobile' => 0 ],
			'blocked_reasons' => [ 'tier2_unknown_challenge' => 1 ],
			'total_pages'     => 1,
		] );

		$this->assertStringContainsString( 'unidentified', $html );
		$this->assertStringContainsString( 'temporarily disable bot protection', $html );
		$this->assertStringNotContainsString( 'rate-limited', $html );
	}
}

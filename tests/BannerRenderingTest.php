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
		WP_Mock::userFunction( 'get_option' )
			->with( AIAS_Broken_Banner::OPTION_DISMISSALS, [] )
			->andReturn( [] );

		// WP output-escaping stubs.
		WP_Mock::userFunction( 'esc_attr' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'esc_html__' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'esc_html_e' )->andReturnUsing( function ( $t ) { echo $t; } );
		WP_Mock::userFunction( 'esc_html' )->andReturnArg( 0 );
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnArg( 0 );

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
}

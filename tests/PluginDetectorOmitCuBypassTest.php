<?php
// tests/PluginDetectorOmitCuBypassTest.php
namespace CUScanner\Tests;

use CUScanner\Scanner\PluginDetector;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * The operator can opt out of Code Unloader's `?nowpcu` bypass suffix.
 *
 * P17: detect() takes no arguments and reads the option through a real Settings
 * object, so every case below exercises the production config lookup. There is no
 * injection seam to fake — a test that passed the flag in as an argument would not
 * discharge this, which is why detect() must not grow one.
 */
class PluginDetectorOmitCuBypassTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * @param string   $cu_version  Version get_plugin_data() reports for Code Unloader.
	 * @param bool     $cu_active   Whether Code Unloader is installed + active.
	 * @param string   $omit        Stored value of cu_scanner_omit_cu_bypass.
	 * @param string[] $also_active Other plugin files to report active.
	 */
	private function detect_with( string $cu_version, bool $cu_active, string $omit, array $also_active = [] ): array {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturnUsing(
			function ( $file ) use ( $cu_active, $also_active ) {
				if ( 'code-unloader/code-unloader.php' === $file ) {
					return $cu_active;
				}
				return in_array( $file, $also_active, true );
			}
		);
		WP_Mock::userFunction( 'get_plugin_data' )->andReturn( [ 'Version' => $cu_version ] );
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $k, $default = false ) use ( $omit ) {
				return ( 'cu_scanner_omit_cu_bypass' === $k ) ? $omit : $default;
			}
		);
		WP_Mock::userFunction( 'wp_parse_url' )
			->andReturnUsing( fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://site.test' );

		return ( new PluginDetector() )->detect();
	}

	public function test_suffix_is_applied_by_default(): void {
		$out = $this->detect_with( '1.4.0', true, '' );
		$this->assertSame( [ 'nowpcu' ], $out['auto_bypass']['code-unloader'] ?? null );
		$this->assertSame( 'Code Unloader', $out['auto_bypass_labels']['code-unloader'] ?? null );
	}

	public function test_option_suppresses_the_suffix_and_its_label(): void {
		$out = $this->detect_with( '1.4.0', true, '1' );
		$this->assertArrayNotHasKey( 'code-unloader', $out['auto_bypass'] );
		$this->assertArrayNotHasKey( 'code-unloader', $out['auto_bypass_labels'] );
	}

	/**
	 * The upgrade soft-block exists only to explain why the bypass is unavailable.
	 * An operator who has opted out has declined the bypass, so the message would be
	 * arguing for something they do not want.
	 */
	public function test_option_also_suppresses_the_old_version_soft_block(): void {
		$out = $this->detect_with( '1.0.0', true, '1' );
		$this->assertArrayNotHasKey( 'Code Unloader', $out['soft_block'] );
	}

	public function test_old_version_still_soft_blocks_when_option_is_off(): void {
		$out = $this->detect_with( '1.0.0', true, '' );
		$this->assertArrayHasKey( 'Code Unloader', $out['soft_block'] );
	}

	/**
	 * cu_missing describes installation, not bypass — other surfaces depend on it,
	 * so the opt-out must not touch it.
	 */
	public function test_cu_missing_is_unaffected_by_the_option(): void {
		$out = $this->detect_with( '1.4.0', false, '1' );
		$this->assertTrue( $out['cu_missing'] );
	}

	/** The opt-out is Code-Unloader-only; no other plugin's bypass key may be lost. */
	public function test_other_plugins_bypass_keys_are_untouched(): void {
		$out = $this->detect_with( '1.4.0', true, '1', [ 'wp-rocket/wp-rocket.php' ] );
		$this->assertArrayNotHasKey( 'code-unloader', $out['auto_bypass'] );
		$this->assertArrayHasKey( 'wp-rocket', $out['auto_bypass'] );
	}
}

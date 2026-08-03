<?php
// tests/SettingsOmitCuBypassTest.php
namespace CUScanner\Tests;

use CUScanner\Settings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class SettingsOmitCuBypassTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_defaults_to_false_when_never_saved(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'cu_scanner_omit_cu_bypass', '' )
			->andReturn( '' );
		$this->assertFalse( ( new Settings() )->get_omit_cu_bypass() );
	}

	public function test_is_true_when_stored_on(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'cu_scanner_omit_cu_bypass', '' )
			->andReturn( '1' );
		$this->assertTrue( ( new Settings() )->get_omit_cu_bypass() );
	}

	public function test_setter_writes_one_when_on(): void {
		WP_Mock::userFunction( 'update_option' )
			->with( 'cu_scanner_omit_cu_bypass', '1' )
			->once()
			->andReturn( true );
		( new Settings() )->set_omit_cu_bypass( true );
		$this->assertConditionsMet();
	}

	public function test_setter_writes_empty_when_off(): void {
		WP_Mock::userFunction( 'update_option' )
			->with( 'cu_scanner_omit_cu_bypass', '' )
			->once()
			->andReturn( true );
		( new Settings() )->set_omit_cu_bypass( false );
		$this->assertConditionsMet();
	}
}

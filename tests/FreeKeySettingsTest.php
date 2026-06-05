<?php
namespace CUScanner\Tests;

use CUScanner\Settings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class FreeKeySettingsTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_detects_free_and_pending_keys(): void {
        $settings = new Settings();

        $this->assertTrue( $settings->is_free_key( 'cusk_Freekey_12' ) );
        $this->assertFalse( $settings->is_free_key( 'cusk_Freekey_?' ) );
        $this->assertTrue( $settings->is_pending_free_key( 'cusk_Freekey_?' ) );
    }

    public function test_buy_credits_url_includes_context_for_free_key(): void {
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://www.Example.com/site' );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_paid_key_claim_token', '' )
            ->andReturn( str_repeat( 'a', 64 ) );

        $settings = new Settings();
        $url      = $settings->get_buy_credits_url( 'cusk_Freekey_12' );

        $this->assertStringContainsString( 'cu_free_key=cusk_Freekey_12', $url );
        $this->assertStringContainsString( 'cu_domain=example.com', $url );
        $this->assertStringContainsString( 'cu_claim_token=' . str_repeat( 'a', 64 ), $url );
    }
}

<?php
// tests/SettingsTest.php
namespace CUScanner\Tests;

use CUScanner\Settings;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class SettingsTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }
    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_get_api_key_returns_stored_value(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( 'sk-test-123' );
        $this->assertSame( 'sk-test-123', ( new Settings() )->get_api_key() );
    }

    public function test_set_api_key_calls_update_option(): void {
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_api_key', 'sk-new-key' )
            ->once();
        ( new Settings() )->set_api_key( 'sk-new-key' );
        $this->assertConditionsMet();
    }

    public function test_get_railway_url_returns_stored_value(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_railway_url', '' )
            ->andReturn( 'https://railway.example.com' );
        $this->assertSame( 'https://railway.example.com', ( new Settings() )->get_railway_url() );
    }

    public function test_set_http_auth_encrypts_before_storing(): void {
        WP_Mock::userFunction( 'wp_salt' )->andReturn( str_repeat( 'x', 64 ) );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_http_auth', \Mockery::type( 'string' ) )
            ->once()
            ->andReturn( true );
        // Should not throw
        ( new Settings() )->set_http_auth( 'user', 'pass' );
        $this->assertConditionsMet();
    }

    public function test_get_http_auth_returns_null_when_empty(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_http_auth', '' )
            ->andReturn( '' );
        $this->assertNull( ( new Settings() )->get_http_auth() );
    }

    public function test_get_scanner_secret_returns_stored_value(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_secret', '' )
            ->andReturn( 'existing-secret-abc' );
        $this->assertSame( 'existing-secret-abc', ( new Settings() )->get_scanner_secret() );
    }

    public function test_get_scanner_secret_generates_and_stores_when_empty(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_secret', '' )
            ->andReturn( '' );
        WP_Mock::userFunction( 'wp_generate_uuid4' )
            ->once()
            ->andReturn( 'generated-uuid-1234' );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_secret', 'generated-uuid-1234' )
            ->once();
        $secret = ( new Settings() )->get_scanner_secret();
        $this->assertSame( 'generated-uuid-1234', $secret );
        $this->assertConditionsMet();
    }
}

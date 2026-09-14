<?php
// tests/RegenerateSecretAjaxTest.php
namespace CUScanner\Tests;

use CUScanner\Admin\SettingsAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class RegenerateSecretAjaxTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_regenerate_secret_rejects_without_capability_before_any_state_change(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )
            ->with( 'cu_scanner_settings_nonce', 'nonce' )->once()->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )->andReturn( false );
        WP_Mock::userFunction( 'wp_send_json_error' )
            ->once()->with( 'Forbidden', 403 )
            ->andThrow( new \Exception( 'sent' ) );
        WP_Mock::userFunction( 'update_option' )->never();

        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'sent' );

        ( new SettingsAjax() )->regenerate_secret();
        $this->assertConditionsMet();
    }

    public function test_regenerate_secret_checks_nonce_cap_and_stores_new_value(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )
            ->with( 'cu_scanner_settings_nonce', 'nonce' )->once()->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )->andReturn( true );

        $captured = null;
        WP_Mock::userFunction( 'update_option' )
            ->with(
                'cu_scanner_secret',
                \Mockery::on( function ( $val ) use ( &$captured ) {
                    $captured = $val;
                    return is_string( $val ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $val );
                } ),
                false
            )
            ->once();

        $sent = null;
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->once()
            ->andReturnUsing( function ( $data = null ) use ( &$sent ) {
                $sent = $data;
                throw new \Exception( 'sent' );
            } );

        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'sent' );

        try {
            ( new SettingsAjax() )->regenerate_secret();
        } finally {
            $this->assertNotNull( $captured, 'update_option( cu_scanner_secret, ... ) was never called' );
            $this->assertSame( $captured, $sent['secret'] ?? null, 'the value sent to the client must match the value stored' );
        }
    }
}

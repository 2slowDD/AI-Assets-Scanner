<?php
// tests/AckCdnAjaxTest.php
namespace CUScanner\Tests;

use CUScanner\Admin\SettingsAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class AckCdnAjaxTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_ack_cdn_checks_nonce_cap_and_stores_name(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )
            ->with( 'cu_scanner_settings_nonce', 'nonce' )->once()->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )->andReturn( true );
        WP_Mock::userFunction( 'wp_unslash' )
            ->andReturnUsing( fn( $v ) => $v );
        WP_Mock::userFunction( 'sanitize_text_field' )
            ->andReturnUsing( fn( $v ) => $v );
        WP_Mock::userFunction( 'update_option' )
            ->once()->with( 'cu_scanner_cdn_exemption_ack', 'cloudflare' );
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->once()
            ->andThrow( new \Exception( 'sent' ) );

        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'sent' );

        $_POST['cdn'] = 'cloudflare';
        ( new SettingsAjax() )->ack_cdn();
        $this->assertConditionsMet();
    }
}

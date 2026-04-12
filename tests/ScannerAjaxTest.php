<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class ScannerAjaxTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    private function mockCheck(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( true );
        WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
    }

    public function test_check_job_returns_error_when_no_transient(): void {
        $this->mockCheck();
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        WP_Mock::userFunction( 'get_transient' )
            ->with( 'cu_scanner_job_1' )
            ->andReturn( false );
        WP_Mock::userFunction( 'wp_send_json_error' )
            ->once()
            ->with( 'No active job' );

        ( new ScannerAjax() )->check_job();
        $this->assertConditionsMet();
    }

    public function test_check_job_returns_job_data_when_transient_exists(): void {
        $this->mockCheck();
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        WP_Mock::userFunction( 'get_transient' )
            ->with( 'cu_scanner_job_1' )
            ->andReturn( [
                'job_id'       => 'abc123',
                'job_token'    => 'tok456',
                'railway_url'  => 'https://railway.example.com',
                'bypass_token' => 'byp789',
            ] );
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->once()
            ->with( [
                'job_id'      => 'abc123',
                'job_token'   => 'tok456',
                'railway_url' => 'https://railway.example.com',
            ] );

        ( new ScannerAjax() )->check_job();
        $this->assertConditionsMet();
    }

    public function test_detect_plugins_returns_null_balance_when_api_fails(): void {
        $this->mockCheck();
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )->andReturn( 'test-key' );
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://example.com' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturn( 'example.com' );
        WP_Mock::userFunction( 'wp_remote_get' )
            ->andReturn( new \WP_Error( 'http_failure', 'Connection refused' ) );

        $captured = null;
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->once()
            ->andReturnUsing( function ( $data ) use ( &$captured ) { $captured = $data; } );

        ( new ScannerAjax() )->detect_plugins();
        $this->assertConditionsMet();
        $this->assertArrayHasKey( 'balance', $captured );
        $this->assertNull( $captured['balance'] );
    }

    public function test_detect_plugins_returns_balance_when_api_succeeds(): void {
        $this->mockCheck();
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )->andReturn( 'test-key' );
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://example.com' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturn( 'example.com' );
        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [ 'response' => [ 'code' => 200 ] ] );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{"balance":42}' );

        $captured = null;
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->once()
            ->andReturnUsing( function ( $data ) use ( &$captured ) { $captured = $data; } );

        ( new ScannerAjax() )->detect_plugins();
        $this->assertConditionsMet();
        $this->assertArrayHasKey( 'balance', $captured );
        $this->assertSame( 42, $captured['balance'] );
    }
}

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
                'railway_url'  => 'https://cu-scanner-railway-production.up.railway.app',
                'bypass_token' => 'byp789',
            ] );
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->once()
            ->with( [
                'job_id'      => 'abc123',
                'job_token'   => 'tok456',
                'railway_url' => 'https://cu-scanner-railway-production.up.railway.app',
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

    public function test_reserve_job_hydrates_missing_railway_url_before_reserving(): void {
        $this->mockCheck();
        $_POST['page_count'] = '1';

        WP_Mock::userFunction( 'absint' )->andReturnUsing( function ( $value ) {
            return max( 0, (int) $value );
        } );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( 'cusk_Freekey_10' );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_railway_url', '' )
            ->andReturn( '' );
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://www.example.com' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( string $url, ?int $component = null ) {
            $parts = parse_url( $url );
            if ( null === $component ) {
                return $parts;
            }
            $map = [
                PHP_URL_SCHEME => 'scheme',
                PHP_URL_HOST   => 'host',
                PHP_URL_PORT   => 'port',
                PHP_URL_USER   => 'user',
                PHP_URL_PASS   => 'pass',
            ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing( function ( string $url ) {
            if ( str_contains( $url, '/cu-scanner/v1/auth' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '{"railway_url":"https://cu-scanner-railway-production.up.railway.app","balance":3}',
                ];
            }
            if ( str_contains( $url, '/cu-scanner/v1/jobs/reserve' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '{"job_token":"plain-token","balance_after":2}',
                ];
            }
            return [ 'response' => [ 'code' => 404 ], 'body' => '{"message":"not found"}' ];
        } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( function ( array $response ) {
            return (int) ( $response['response']['code'] ?? 0 );
        } );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( function ( array $response ) {
            return (string) ( $response['body'] ?? '' );
        } );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_railway_url', 'https://cu-scanner-railway-production.up.railway.app' )
            ->once();
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 11 );
        WP_Mock::userFunction( 'set_transient' )
            ->with( 'cu_scanner_pending_token_11', 'plain-token', 3600 )
            ->once();

        $captured = null;
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->once()
            ->andReturnUsing( function ( $data ) use ( &$captured ) { $captured = $data; } );

        ( new ScannerAjax() )->reserve_job();

        unset( $_POST['page_count'] );
        $this->assertConditionsMet();
        $this->assertSame( [ 'reserved' => true, 'job_token' => 'plain-token' ], $captured );
    }

    public function test_format_submit_error_detail_short_message_is_untruncated(): void {
        $result = ScannerAjax::format_submit_error_detail( 'Railway HTTP 401: no such token' );
        $this->assertSame( 'Scan submission failed: Railway HTTP 401: no such token', $result );
    }

    public function test_format_submit_error_detail_truncates_at_80_chars_with_ellipsis(): void {
        $long   = str_repeat( 'x', 200 );
        $result = ScannerAjax::format_submit_error_detail( $long );

        // Prefix is fixed literal text
        $this->assertStringStartsWith( 'Scan submission failed: ', $result );

        // Detail portion = first 80 chars of input + ellipsis
        $detail = mb_substr( $result, mb_strlen( 'Scan submission failed: ' ) );
        $this->assertSame( str_repeat( 'x', 80 ) . '…', $detail );
    }

    public function test_format_submit_error_detail_at_exactly_80_chars_no_ellipsis(): void {
        $exact  = str_repeat( 'x', 80 );
        $result = ScannerAjax::format_submit_error_detail( $exact );
        $this->assertSame( 'Scan submission failed: ' . $exact, $result );
        $this->assertStringEndsNotWith( '…', $result );
    }

    public function test_format_reserve_error_detail_short_message_is_untruncated(): void {
        $result = ScannerAjax::format_reserve_error_detail( 'HTTP 429: rate limited' );
        $this->assertSame( 'Could not reserve credits: HTTP 429: rate limited', $result );
    }

    public function test_format_reserve_error_detail_truncates_at_80_chars_with_ellipsis(): void {
        $long   = str_repeat( 'y', 200 );
        $result = ScannerAjax::format_reserve_error_detail( $long );

        $this->assertStringStartsWith( 'Could not reserve credits: ', $result );

        $detail = mb_substr( $result, mb_strlen( 'Could not reserve credits: ' ) );
        $this->assertSame( str_repeat( 'y', 80 ) . '…', $detail );
    }

    public function test_format_reserve_error_detail_at_exactly_80_chars_no_ellipsis(): void {
        $exact  = str_repeat( 'y', 80 );
        $result = ScannerAjax::format_reserve_error_detail( $exact );
        $this->assertSame( 'Could not reserve credits: ' . $exact, $result );
        $this->assertStringEndsNotWith( '…', $result );
    }

    public function test_billable_count_excludes_origin_unavailable(): void {
        $pages = [
            [ 'status' => 'done' ],
            [ 'status' => 'done' ],
            [ 'status' => 'origin_unavailable' ],   // skipped — must NOT bill
            [ 'status' => 'error' ],                 // already excluded
        ];
        $this->assertSame( 2, ScannerAjax::billable_page_count( $pages ) );
    }
}

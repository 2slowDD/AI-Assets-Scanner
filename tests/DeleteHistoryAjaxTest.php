<?php
// tests/DeleteHistoryAjaxTest.php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class DeleteHistoryAjaxTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_delete_history_happy_path_delegates_to_scan_history_and_sets_transient_and_succeeds(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )
            ->with( 'cu_scanner_nonce', 'nonce' )->once()->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )->andReturn( true );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [
                [ 'job_id' => 'job-a' ],
                [ 'job_id' => 'job-b' ],
            ] );
        WP_Mock::userFunction( 'delete_option' )->with( 'cu_scanner_json_job-a' )->once();
        WP_Mock::userFunction( 'delete_option' )->with( 'cu_scanner_json_job-b' )->once();
        WP_Mock::userFunction( 'delete_option' )->with( 'cu_scanner_history' )->once();
        WP_Mock::userFunction( 'set_transient' )
            ->with( 'cu_scanner_history_deleted_notice', 2, 30 )->once();
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->with( [ 'deleted' => 2 ] )->once()
            ->andThrow( new \Exception( 'sent' ) );

        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'sent' );
        ( new ScannerAjax() )->delete_history();
        $this->assertConditionsMet();
    }

    public function test_delete_history_missing_cap_returns_403(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )
            ->with( 'cu_scanner_nonce', 'nonce' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )->andReturn( false );
        WP_Mock::userFunction( 'wp_send_json_error' )
            ->with( 'Forbidden', 403 )->once()
            ->andThrow( new \Exception( 'forbidden' ) );

        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'forbidden' );
        ( new ScannerAjax() )->delete_history();
        $this->assertConditionsMet();
    }
}

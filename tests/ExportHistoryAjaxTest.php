<?php
// tests/ExportHistoryAjaxTest.php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class ExportHistoryAjaxTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_export_history_missing_cap_calls_wp_die_403(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )
            ->with( 'cu_scanner_nonce', 'nonce' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )->andReturn( false );
        WP_Mock::userFunction( 'wp_die' )
            ->with( 'Forbidden', '', [ 'response' => 403 ] )->once()
            ->andThrow( new \Exception( 'forbidden' ) );

        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'forbidden' );
        ( new ScannerAjax() )->export_history();
        $this->assertConditionsMet();
    }

    public function test_export_history_empty_returns_plain_text_no_download(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )
            ->with( 'cu_scanner_nonce', 'nonce' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )->andReturn( true );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )->andReturn( [] );
        WP_Mock::userFunction( 'wp_die' )
            ->with( 'No history to export', '', [ 'response' => 200 ] )->once()
            ->andThrow( new \Exception( 'empty' ) );

        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'empty' );
        ( new ScannerAjax() )->export_history();
        $this->assertConditionsMet();
    }
}

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

    public function test_csv_cell_defuses_formula_injection_characters(): void {
        $rc = new \ReflectionClass( ScannerAjax::class );
        $m  = $rc->getMethod( 'csv_cell' );
        $m->setAccessible( true );
        $obj = new ScannerAjax();
        $this->assertSame( "'=cmd",   $m->invoke( $obj, '=cmd' ) );
        $this->assertSame( "'+cmd",   $m->invoke( $obj, '+cmd' ) );
        $this->assertSame( "'-cmd",   $m->invoke( $obj, '-cmd' ) );
        $this->assertSame( "'@cmd",   $m->invoke( $obj, '@cmd' ) );
        $this->assertSame( "'\tcmd",  $m->invoke( $obj, "\tcmd" ) );
        $this->assertSame( "'\rcmd",  $m->invoke( $obj, "\rcmd" ) );
        $this->assertSame( 'safe',    $m->invoke( $obj, 'safe' ) );
        $this->assertSame( '',        $m->invoke( $obj, '' ) );
    }

    public function test_write_csv_emits_bom_header_and_data_rows(): void {
        $records = [
            [
                'job_id' => 'job-a', 'domain' => 'example.com',
                'page_count' => 10, 'credits_used' => 5,
                'safe_count' => 3, 'aggressive_count' => 1,
                'status' => 'complete', 'created_at' => '2026-04-24T10:00:00+00:00',
            ],
            [
                'job_id' => 'job-b', 'domain' => '=EVIL',
                'page_count' => 1, 'credits_used' => 0,
                'safe_count' => 0, 'aggressive_count' => 0,
                'status' => 'failed', 'created_at' => '2026-04-23T09:00:00+00:00',
            ],
        ];
        $rc = new \ReflectionClass( ScannerAjax::class );
        $m  = $rc->getMethod( 'write_csv' );
        $m->setAccessible( true );

        $fh = fopen( 'php://memory', 'w+' );
        $m->invoke( new ScannerAjax(), $fh, $records );
        rewind( $fh );
        $out = stream_get_contents( $fh );
        fclose( $fh );

        // UTF-8 BOM first 3 bytes
        $this->assertSame( "\xEF\xBB\xBF", substr( $out, 0, 3 ) );
        // Header row present (fputcsv quotes columns with spaces)
        $this->assertStringContainsString( 'Date,Domain,Pages,Credits,"Safe Rules","Aggressive Rules",Status,"Job ID"', $out );
        // Data rows present
        $this->assertStringContainsString( 'example.com', $out );
        // Formula-injection defuse on domain cell
        $this->assertStringContainsString( "'=EVIL", $out );
    }
}

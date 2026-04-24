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

    public function test_export_history_streams_csv_when_zip_unavailable(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )
            ->with( 'cu_scanner_nonce', 'nonce' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )->andReturn( true );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [
                'job_id' => 'job-a', 'domain' => 'example.com',
                'page_count' => 1, 'credits_used' => 0,
                'safe_count' => 0, 'aggressive_count' => 0,
                'status' => 'complete', 'created_at' => '2026-04-24T10:00:00+00:00',
            ] ] );

        ob_start();
        try {
            ( new ForcedCsvScannerAjax() )->export_history();
            $this->fail( 'Expected terminate() to throw' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'terminated', $e->getMessage() );
        }
        $out = ob_get_clean();

        $this->assertStringContainsString( "\xEF\xBB\xBF", $out );
        $this->assertStringContainsString( 'example.com', $out );
        $this->assertConditionsMet();
    }

    public function test_export_history_writes_zip_with_expected_file_list(): void {
        if ( ! class_exists( 'ZipArchive' ) ) {
            $this->markTestSkipped( 'ZipArchive unavailable in test env' );
        }
        WP_Mock::userFunction( 'check_ajax_referer' )
            ->with( 'cu_scanner_nonce', 'nonce' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )->andReturn( true );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [
                [
                    'job_id' => 'job-a', 'domain' => 'a.com',
                    'page_count' => 1, 'credits_used' => 0,
                    'safe_count' => 0, 'aggressive_count' => 0,
                    'status' => 'complete', 'created_at' => '2026-04-24T10:00:00+00:00',
                ],
                [
                    'job_id' => 'job-b', 'domain' => 'b.com',
                    'page_count' => 2, 'credits_used' => 1,
                    'safe_count' => 1, 'aggressive_count' => 1,
                    'status' => 'complete', 'created_at' => '2026-04-23T09:00:00+00:00',
                ],
            ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_json_job-a', '' )->andReturn( '{"a":1}' );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_json_job-b', '' )->andReturn( '{"b":2}' );
        WP_Mock::userFunction( 'wp_tempnam' )
            ->andReturnUsing( function () {
                return tempnam( sys_get_temp_dir(), 'cu-hist-' );
            } );
        WP_Mock::userFunction( 'wp_json_encode' )
            ->andReturnUsing( function ( $data, $flags = 0 ) {
                return json_encode( $data, $flags );
            } );

        $subject = new class extends \CUScanner\Admin\ScannerAjax {
            public ?string $captured_tmp = null;
            protected function terminate(): void { throw new \RuntimeException( 'terminated' ); }
            protected function stream_zip( string $tmp ): void {
                $this->captured_tmp = $tmp;
                $this->terminate();
            }
        };

        try {
            $subject->export_history();
            $this->fail( 'Expected terminate()' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'terminated', $e->getMessage() );
        }

        $this->assertNotNull( $subject->captured_tmp );
        $this->assertFileExists( $subject->captured_tmp );

        $zip = new \ZipArchive();
        $this->assertTrue( $zip->open( $subject->captured_tmp ) === true );
        $names = [];
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $names[] = $zip->getNameIndex( $i );
        }
        $zip->close();
        @unlink( $subject->captured_tmp );

        sort( $names );
        $this->assertSame(
            [ 'README.txt', 'history.csv', 'history.json', 'scans/job-a.json', 'scans/job-b.json' ],
            $names
        );
        $this->assertConditionsMet();
    }

    public function test_export_history_falls_through_to_csv_when_zip_open_fails(): void {
        if ( ! class_exists( 'ZipArchive' ) ) {
            $this->markTestSkipped( 'ZipArchive unavailable' );
        }
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [
                'job_id' => 'job-a', 'domain' => 'a.com',
                'page_count' => 1, 'credits_used' => 0,
                'safe_count' => 0, 'aggressive_count' => 0,
                'status' => 'complete', 'created_at' => '2026-04-24T10:00:00+00:00',
            ] ] );

        // Make wp_tempnam return a directory path → ZipArchive::open will fail.
        $fake_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cu-fake-dir-' . uniqid();
        mkdir( $fake_dir );
        WP_Mock::userFunction( 'wp_tempnam' )->andReturn( $fake_dir );

        $subject = new class extends \CUScanner\Admin\ScannerAjax {
            public bool $zip_stream_called = false;
            protected function terminate(): void { throw new \RuntimeException( 'terminated' ); }
            protected function stream_zip( string $tmp ): void {
                $this->zip_stream_called = true;
                $this->terminate();
            }
            protected function emit_csv_headers( string $filename ): void {} // no-op in test
        };

        ob_start();
        try {
            $subject->export_history();
            $this->fail( 'Expected terminate()' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'terminated', $e->getMessage() );
        }
        $out = ob_get_clean();
        @rmdir( $fake_dir );

        $this->assertFalse( $subject->zip_stream_called, 'stream_zip must NOT be called when open() fails' );
        $this->assertStringContainsString( "\xEF\xBB\xBF", $out );
        $this->assertStringContainsString( 'a.com', $out );
        $this->assertConditionsMet();
    }
}

class ForcedCsvScannerAjax extends \CUScanner\Admin\ScannerAjax {
    protected function zip_available(): bool { return false; }
    protected function terminate(): void { throw new \RuntimeException( 'terminated' ); }
    protected function emit_csv_headers( string $filename ): void {} // no-op in test
}

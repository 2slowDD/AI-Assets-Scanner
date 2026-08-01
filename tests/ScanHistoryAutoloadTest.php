<?php

use WP_Mock\Tools\TestCase;

/** FU-AAS-AUTOLOAD-BLOAT: every ScanHistory write must pass autoload=false. */
class ScanHistoryAutoloadTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_create_record_writes_history_with_autoload_false(): void {
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_history', [] )->andReturn( [] );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_history', \Mockery::type( 'array' ), false )
            ->once()
            ->andReturn( true );

        ( new \CUScanner\ScanHistory() )->create_record( 'job-1', 'example.test', 5, 'queued' );
    }

    public function test_update_status_writes_history_with_autoload_false(): void {
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_history', [] )->andReturn(
            [ [ 'job_id' => 'job-1', 'status' => 'queued' ] ]
        );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_history', \Mockery::type( 'array' ), false )
            ->once()
            ->andReturn( true );

        ( new \CUScanner\ScanHistory() )->update_status( 'job-1', 'done' );
    }

    public function test_store_json_writes_blob_with_autoload_false(): void {
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_json_job-1', '{"a":1}', false )
            ->once()
            ->andReturn( true );

        ( new \CUScanner\ScanHistory() )->store_json( 'job-1', '{"a":1}' );
    }
}

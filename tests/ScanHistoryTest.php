<?php
// tests/ScanHistoryTest.php
namespace CUScanner\Tests;

use CUScanner\ScanHistory;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class ScanHistoryTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_create_record_prepends_to_history(): void {
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_history', [] )->andReturn( [] );
        WP_Mock::userFunction( 'update_option' )->once();
        ( new ScanHistory() )->create_record( 'job-1', 'site.com', 10, 'in_progress' );
        $this->assertConditionsMet();
    }

    public function test_history_capped_at_10_records(): void {
        $existing = array_map( fn($i) => [ 'job_id' => "job-{$i}" ], range( 1, 10 ) );
        WP_Mock::userFunction( 'get_option' )->with( 'cu_scanner_history', [] )->andReturn( $existing );
        WP_Mock::userFunction( 'delete_option' )->once();
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_history', \Mockery::type( 'array' ) )
            ->andReturnUsing( function( $key, $value ) {
                $this->assertCount( 10, $value );
                return true;
            } );
        ( new ScanHistory() )->create_record( 'job-11', 'site.com', 5, 'in_progress' );
        $this->assertConditionsMet();
    }

    public function test_store_json_saves_to_option(): void {
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_json_job-1', '{"version":"1.3.6"}' )
            ->once();
        ( new ScanHistory() )->store_json( 'job-1', '{"version":"1.3.6"}' );
        $this->assertConditionsMet();
    }

    public function test_get_json_retrieves_stored_value(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_json_job-1', '' )
            ->andReturn( '{"version":"1.3.6"}' );
        $result = ( new ScanHistory() )->get_json( 'job-1' );
        $this->assertSame( '{"version":"1.3.6"}', $result );
    }

    public function test_update_status_changes_record_status(): void {
        $existing = [ [ 'job_id' => 'job-1', 'status' => 'in_progress' ] ];
        WP_Mock::userFunction( 'get_option' )->andReturn( $existing );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_history', \Mockery::type( 'array' ) )
            ->andReturnUsing( function( $key, $records ) {
                $this->assertSame( 'complete', $records[0]['status'] );
                return true;
            } );
        ( new ScanHistory() )->update_status( 'job-1', 'complete', [ 'credits_used' => 10 ] );
        $this->assertConditionsMet();
    }
}

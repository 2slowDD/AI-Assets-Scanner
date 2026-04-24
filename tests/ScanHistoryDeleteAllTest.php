<?php
// tests/ScanHistoryDeleteAllTest.php
namespace CUScanner\Tests;

use CUScanner\ScanHistory;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class ScanHistoryDeleteAllTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_delete_all_with_empty_history_returns_zero(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [] );
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'cu_scanner_history' )
            ->once();
        $count = ( new ScanHistory() )->delete_all();
        $this->assertSame( 0, $count );
        $this->assertConditionsMet();
    }

    public function test_delete_all_with_three_records_returns_three_and_deletes_per_job_options(): void {
        $existing = [
            [ 'job_id' => 'job-a' ],
            [ 'job_id' => 'job-b' ],
            [ 'job_id' => 'job-c' ],
        ];
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( $existing );
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'cu_scanner_json_job-a' )->once();
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'cu_scanner_json_job-b' )->once();
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'cu_scanner_json_job-c' )->once();
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'cu_scanner_history' )->once();

        $count = ( new ScanHistory() )->delete_all();
        $this->assertSame( 3, $count );
        $this->assertConditionsMet();
    }

    public function test_delete_all_tolerates_record_without_job_id(): void {
        $existing = [
            [ 'job_id' => 'job-a' ],
            [ /* malformed: no job_id */ ],
        ];
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( $existing );
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'cu_scanner_json_job-a' )->once();
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'cu_scanner_history' )->once();

        $count = ( new ScanHistory() )->delete_all();
        $this->assertSame( 2, $count );
        $this->assertConditionsMet();
    }
}

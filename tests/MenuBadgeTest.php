<?php
namespace CUScanner\Tests;

use CUScanner\MenuBadge;
use CUScanner\ScanHistory;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class MenuBadgeTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // --- AC-MB-1: fresh install ---

    public function test_no_history_returns_null_badge_state(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [] );

        $badge = new MenuBadge();
        $this->assertNull( $badge->get_badge_state() );
    }

    // --- AC-MB-10: queued-only history (in-flight scan) ---

    public function test_only_queued_scans_returns_null(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [ 'job_id' => 'aaa', 'status' => 'queued' ] ] );

        $badge = new MenuBadge();
        $this->assertNull( $badge->get_badge_state() );
    }

    // --- AC-MB-2 + AC-MB-5: complete-unseen → green ---

    public function test_complete_unseen_returns_green(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [ 'job_id' => 'newjobid', 'status' => 'complete' ] ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( '' );

        $badge = new MenuBadge();
        $this->assertSame( 'green', $badge->get_badge_state() );
    }

    // --- AC-MB-7: failed-unseen → red ---

    public function test_failed_unseen_returns_red(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [ 'job_id' => 'failed1', 'status' => 'failed' ] ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( '' );

        $badge = new MenuBadge();
        $this->assertSame( 'red', $badge->get_badge_state() );
    }

    // --- AC-MB-3 + AC-MB-4: complete-seen → null ---

    public function test_complete_seen_returns_null(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [ 'job_id' => 'samejobid', 'status' => 'complete' ] ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( 'samejobid' );

        $badge = new MenuBadge();
        $this->assertNull( $badge->get_badge_state() );
    }

    // --- AC-MB-8: most-recent (newest) wins regardless of older states ---

    public function test_complete_after_failed_returns_green(): void {
        // History is newest-first (ScanHistory::create_record uses array_unshift).
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [
                [ 'job_id' => 'newest', 'status' => 'complete' ],
                [ 'job_id' => 'older',  'status' => 'failed' ],
            ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( '' );

        $badge = new MenuBadge();
        $this->assertSame( 'green', $badge->get_badge_state() );
    }
}

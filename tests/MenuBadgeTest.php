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
}

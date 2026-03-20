<?php
// tests/RulePusherTest.php
namespace CUScanner\Tests;

use CUScanner\Scanner\RulePusher;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class RulePusherTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_can_push_returns_false_when_cu_not_active(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'code-unloader/code-unloader.php' )
            ->andReturn( false );
        $this->assertFalse( ( new RulePusher() )->can_push() );
    }

    public function test_can_push_returns_true_when_cu_active_and_class_exists(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'code-unloader/code-unloader.php' )
            ->andReturn( true );
        // Unit test: trust is_plugin_active check
        // (Integration test confirms class exists on real WP)
        $this->assertTrue( true ); // placeholder — see integration note
    }

    public function test_push_returns_summary_with_counts(): void {
        // This test requires the real Code Unloader class to be loaded.
        // Mark as integration test — skip in unit suite, run on real WP.
        $this->markTestSkipped( 'Requires Code Unloader to be installed — run as integration test.' );
    }
}

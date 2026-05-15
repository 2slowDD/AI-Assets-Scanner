<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\OptimizerState;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class OptimizerStateTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_save_writes_option_with_autoload_false(): void {
        $captured = null;
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value, $autoload ) use ( &$captured ) {
                if ( $key === 'aias_optimizer_state' ) {
                    $captured = [ 'value' => $value, 'autoload' => $autoload ];
                }
                return true;
            } );

        OptimizerState::save(
            'abcdef0123456789',
            [ 'sg_optimizer' => [ 'siteground_optimizer_optimize_css' => 1 ] ],
            600
        );

        $this->assertNotNull( $captured );
        $this->assertSame( false, $captured['autoload'], 'autoload must be false' );
        $this->assertSame( 'abcdef0123456789', $captured['value']['scan_id'] );
        $this->assertSame(
            [ 'siteground_optimizer_optimize_css' => 1 ],
            $captured['value']['snapshots']['sg_optimizer']
        );
        $this->assertGreaterThan( time(), $captured['value']['expires_at'] );
        $this->assertLessThanOrEqual( time() + 600, $captured['value']['expires_at'] );
        $this->assertGreaterThanOrEqual( time() - 1, $captured['value']['created_at'] );
    }

    public function test_load_returns_payload_when_present(): void {
        $payload = [
            'scan_id'    => 'abcdef0123456789',
            'created_at' => time() - 5,
            'expires_at' => time() + 600,
            'snapshots'  => [ 'sg_optimizer' => [ 'siteground_optimizer_optimize_css' => 1 ] ],
        ];
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_optimizer_state', null )
            ->andReturn( $payload );

        $loaded = OptimizerState::load();
        $this->assertSame( $payload, $loaded );
    }

    public function test_load_returns_null_when_missing(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_optimizer_state', null )
            ->andReturn( null );

        $this->assertNull( OptimizerState::load() );
    }

    public function test_load_returns_null_when_value_is_not_array(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_optimizer_state', null )
            ->andReturn( 'corrupted' );

        $this->assertNull( OptimizerState::load() );
    }

    public function test_clear_calls_delete_option(): void {
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'aias_optimizer_state' )
            ->once();

        OptimizerState::clear();
        $this->assertConditionsMet();
    }

    public function test_is_orphaned_false_when_state_absent(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( null );
        $this->assertFalse( OptimizerState::is_orphaned() );
    }

    public function test_is_orphaned_false_when_not_yet_expired(): void {
        WP_Mock::userFunction( 'get_option' )
            ->andReturn( [
                'scan_id' => 'sid', 'created_at' => time(), 'expires_at' => time() + 100,
                'snapshots' => [],
            ] );
        $this->assertFalse( OptimizerState::is_orphaned() );
    }

    public function test_is_orphaned_true_when_past_expiry(): void {
        WP_Mock::userFunction( 'get_option' )
            ->andReturn( [
                'scan_id' => 'sid', 'created_at' => time() - 1000,
                'expires_at' => time() - 10, 'snapshots' => [],
            ] );
        $this->assertTrue( OptimizerState::is_orphaned() );
    }

    public function test_is_orphaned_handles_missing_expires_at_field(): void {
        WP_Mock::userFunction( 'get_option' )
            ->andReturn( [ 'scan_id' => 'sid', 'snapshots' => [] ] );
        // No expires_at key — treat as orphaned (defensive: corrupted/legacy state).
        $this->assertTrue( OptimizerState::is_orphaned() );
    }
}

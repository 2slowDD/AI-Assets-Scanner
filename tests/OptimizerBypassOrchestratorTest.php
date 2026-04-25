<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\OptimizerBypassOrchestrator;
use CUScanner\Scanner\OptimizerState;
use CUScanner\Scanner\Strategies\AbstractOptimizerBypass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class OptimizerBypassOrchestratorTest extends TestCase {

    /** Local in-memory option/event capture for one test method. */
    private array $option_store = [];
    private array $emitted_events = [];

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->option_store   = [];
        $this->emitted_events = [];

        // Mock the option backing OptimizerState
        WP_Mock::userFunction( 'get_option' )
            ->andReturnUsing( fn( $key, $default = null ) =>
                $this->option_store[ $key ] ?? $default );
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value, $autoload = null ) {
                $this->option_store[ $key ] = $value;
                return true;
            } );
        WP_Mock::userFunction( 'delete_option' )
            ->andReturnUsing( function ( $key ) {
                unset( $this->option_store[ $key ] );
                return true;
            } );
        // Mock event-queue scheduler functions used by EventEmitter
        WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );
        WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturn( true );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );
        // Watchdog scheduling
        WP_Mock::userFunction( 'as_schedule_single_action' )->andReturn( 1 );
    }

    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    private function make_strategy( string $slug, ?\Throwable $disable_throws = null ): AbstractOptimizerBypass {
        return new class( $slug, $disable_throws ) extends AbstractOptimizerBypass {
            public string $slug;
            public ?\Throwable $disable_throws;
            public bool $disabled  = false;
            public bool $restored  = false;
            public ?array $restored_with = null;
            public function __construct( string $slug, ?\Throwable $disable_throws ) {
                $this->slug = $slug;
                $this->disable_throws = $disable_throws;
            }
            public function slug(): string { return $this->slug; }
            public function snapshot(): array { return [ '_marker' => $this->slug ]; }
            public function disable(): void {
                if ( $this->disable_throws ) throw $this->disable_throws;
                $this->disabled = true;
            }
            public function restore( array $snapshot ): void {
                $this->restored = true;
                $this->restored_with = $snapshot;
            }
        };
    }

    public function test_begin_disables_all_strategies_and_persists_state(): void {
        $a = $this->make_strategy( 'flying_press' );
        $orchestrator = new OptimizerBypassOrchestrator( [ $a ] );
        $orchestrator->begin( 'abcdef0123456789', 600 );

        $this->assertTrue( $a->disabled );
        $this->assertArrayHasKey( 'aias_optimizer_state', $this->option_store );
        $state = $this->option_store['aias_optimizer_state'];
        $this->assertSame( 'abcdef0123456789', $state['scan_id'] );
        $this->assertArrayHasKey( 'flying_press', $state['snapshots'] );
        $this->assertSame( [ '_marker' => 'flying_press' ], $state['snapshots']['flying_press'] );
    }

    public function test_complete_restores_in_reverse_order(): void {
        $a = $this->make_strategy( 'a' );
        $b = $this->make_strategy( 'b' );
        $orchestrator = new OptimizerBypassOrchestrator( [ $a, $b ] );
        $orchestrator->begin( 'abcdef0123456789', 600 );

        $orchestrator->complete( 'normal' );

        $this->assertTrue( $a->restored );
        $this->assertTrue( $b->restored );
        $this->assertArrayNotHasKey( 'aias_optimizer_state', $this->option_store );
    }

    public function test_partial_disable_failure_rolls_back_atomically(): void {
        $a = $this->make_strategy( 'a' );  // succeeds
        $b = $this->make_strategy( 'b', new \RuntimeException( 'boom' ) );  // throws
        $c = $this->make_strategy( 'c' );  // never reached
        $orchestrator = new OptimizerBypassOrchestrator( [ $a, $b, $c ] );

        try {
            $orchestrator->begin( 'abcdef0123456789', 600 );
            $this->fail( 'expected RuntimeException' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'boom', $e->getMessage() );
        }

        $this->assertTrue( $a->disabled, 'a was disabled before b failed' );
        $this->assertTrue( $a->restored, 'a must be rolled back on failure' );
        $this->assertFalse( $c->disabled, 'c never reached' );
        $this->assertArrayNotHasKey( 'aias_optimizer_state', $this->option_store,
            'state must not be persisted when disable failed' );
    }

    public function test_complete_idempotent_when_no_state(): void {
        $a = $this->make_strategy( 'a' );
        $orchestrator = new OptimizerBypassOrchestrator( [ $a ] );
        // No begin() called; complete should silently no-op.
        $orchestrator->complete( 'normal' );
        $this->assertFalse( $a->restored );
    }

    public function test_complete_with_loaded_state_uses_provided_state(): void {
        $a = $this->make_strategy( 'a' );
        // Set state directly with marker payload
        $this->option_store['aias_optimizer_state'] = [
            'scan_id' => 'abcdef0123456789', 'created_at' => time(),
            'expires_at' => time() + 100,
            'snapshots' => [ 'a' => [ '_external' => true ] ],
        ];
        $orchestrator = new OptimizerBypassOrchestrator( [ $a ] );

        $orchestrator->complete_with_loaded_state(
            $this->option_store['aias_optimizer_state'],
            'stale_state'
        );

        $this->assertTrue( $a->restored );
        $this->assertSame( [ '_external' => true ], $a->restored_with );
        $this->assertArrayNotHasKey( 'aias_optimizer_state', $this->option_store );
    }

    public function test_refuse_to_start_when_state_is_orphaned(): void {
        $a = $this->make_strategy( 'a' );
        // Inject orphaned state
        $this->option_store['aias_optimizer_state'] = [
            'scan_id' => 'old-scan', 'created_at' => time() - 1000,
            'expires_at' => time() - 100,  // orphaned
            'snapshots' => [ 'a' => [ '_marker' => 'old' ] ],
        ];
        $orchestrator = new OptimizerBypassOrchestrator( [ $a ] );

        try {
            $orchestrator->begin( 'abcdef0123456789', 600 );
            $this->fail( 'expected RuntimeException for refuse_to_start' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'previous scan', strtolower( $e->getMessage() ) );
        }

        $this->assertTrue( $a->restored, 'self-heal restored the orphaned state' );
        $this->assertSame( [ '_marker' => 'old' ], $a->restored_with );
        $this->assertArrayNotHasKey( 'aias_optimizer_state', $this->option_store,
            'orphaned state cleared after self-heal' );
    }
}

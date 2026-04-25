<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\EventEmitter;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class EventEmitterTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        EventEmitter::set_client_for_testing( null );
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        EventEmitter::set_client_for_testing( null );
        parent::tearDown();
    }

    public function test_emit_appends_to_local_queue(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_pending_events', [] )
            ->andReturn( [] );
        $captured = null;
        WP_Mock::userFunction( 'update_option' )
            ->with( 'aias_pending_events', \Mockery::on( function ( $value ) use ( &$captured ) {
                $captured = $value;
                return true;
            } ), false )
            ->once();
        WP_Mock::userFunction( 'wp_next_scheduled' )
            ->with( 'aias_event_emitter_flush' )
            ->andReturn( false );
        WP_Mock::userFunction( 'wp_schedule_single_event' )->once();

        EventEmitter::emit( 'optimizer_detected', 'operational', [
            'plugin' => 'rocket', 'class' => 'A', 'bypass_query' => 'h', 'scan_id' => 'sid',
        ], 'sid' );

        $this->assertCount( 1, $captured );
        $this->assertSame( 'optimizer_detected', $captured[0]['name'] );
        $this->assertSame( 'sid', $captured[0]['scan_id'] );
    }

    public function test_emit_does_not_reschedule_when_already_scheduled(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( [] );
        WP_Mock::userFunction( 'update_option' )->once();
        WP_Mock::userFunction( 'wp_next_scheduled' )
            ->with( 'aias_event_emitter_flush' )
            ->andReturn( time() + 1 );  // already scheduled
        WP_Mock::userFunction( 'wp_schedule_single_event' )->never();

        EventEmitter::emit( 'optimizer_detected', 'operational', [
            'plugin' => 'rocket', 'class' => 'A', 'bypass_query' => 'h', 'scan_id' => 'sid',
        ], 'sid' );
        $this->assertConditionsMet();
    }

    public function test_queue_overflow_drops_oldest_and_emits_overflow_event(): void {
        // Existing queue at cap (1000 events).
        $existing = [];
        for ( $i = 0; $i < 1000; $i++ ) {
            $existing[] = [
                'name' => 'optimizer_detected', 'category' => 'operational',
                'fields' => [ 'plugin' => 'rocket', 'class' => 'A',
                              'bypass_query' => "h{$i}", 'scan_id' => 'sid' ],
                'scan_id' => 'sid', 'at' => 1000 + $i,
            ];
        }
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_pending_events', [] )
            ->andReturn( $existing );
        WP_Mock::userFunction( 'get_transient' )
            ->with( 'aias_event_overflow_warned' )
            ->andReturn( false );  // no recent overflow warned
        WP_Mock::userFunction( 'set_transient' )
            ->with( 'aias_event_overflow_warned', \Mockery::any(), 60 )
            ->once();
        $captured = null;
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$captured ) {
                $captured = $value;
                return true;
            } );
        WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );
        WP_Mock::userFunction( 'wp_schedule_single_event' );

        EventEmitter::emit( 'optimizer_detected', 'operational', [
            'plugin' => 'rocket', 'class' => 'A', 'bypass_query' => 'h_new', 'scan_id' => 'sid',
        ], 'sid' );

        $this->assertLessThanOrEqual( 1000, count( $captured ),
            'queue must be capped at 1000 after overflow + insert' );
        // Oldest dropped: bypass_query=h0 should not be present
        $present_h0 = array_filter( $captured, fn( $e ) =>
            ( $e['fields']['bypass_query'] ?? '' ) === 'h0' );
        $this->assertEmpty( $present_h0, 'oldest event must be dropped on overflow' );
        // Overflow event must be present
        $overflow = array_filter( $captured, fn( $e ) => $e['name'] === 'event_queue_overflow' );
        $this->assertNotEmpty( $overflow );
    }

    public function test_overflow_rate_limited_within_60_seconds(): void {
        $existing = array_fill( 0, 1000, [
            'name' => 'optimizer_detected', 'category' => 'operational',
            'fields' => [], 'scan_id' => 'sid', 'at' => 1,
        ] );
        WP_Mock::userFunction( 'get_option' )->andReturn( $existing );
        // get_transient returns recent overflow (within 60s)
        WP_Mock::userFunction( 'get_transient' )
            ->with( 'aias_event_overflow_warned' )
            ->andReturn( time() - 10 );
        WP_Mock::userFunction( 'set_transient' )->never();  // not re-warned
        $captured = null;
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$captured ) {
                $captured = $value;
                return true;
            } );
        WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );
        WP_Mock::userFunction( 'wp_schedule_single_event' );

        EventEmitter::emit( 'optimizer_detected', 'operational', [], 'sid' );

        // No new overflow event
        $overflow = array_filter( $captured, fn( $e ) => $e['name'] === 'event_queue_overflow' );
        $this->assertEmpty( $overflow );
    }

    public function test_flush_returns_zero_when_queue_empty(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_pending_events', [] )
            ->andReturn( [] );
        $sent = EventEmitter::flush();
        $this->assertSame( 0, $sent );
    }

    public function test_flush_drains_queue_when_saas_accepts(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_pending_events', [] )
            ->andReturn( [
                [
                    'name' => 'scan_request_received', 'category' => 'operational',
                    'fields' => [ 'scan_id' => 'sid', 'path_hash' => 'p', 'optimizers_active' => 1 ],
                    'scan_id' => 'sid', 'at' => 1,
                ],
            ] );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'aias_pending_events', [], false )
            ->once();

        $stub = new class {
            public array $sent = [];
            public function emit_events( string $scan_id, array $events ): array {
                $this->sent[] = [ 'scan_id' => $scan_id, 'events' => $events ];
                return [ 'accepted' => count( $events ), 'rejected' => 0, 'errors' => [] ];
            }
        };
        EventEmitter::set_client_for_testing( $stub );

        $sent = EventEmitter::flush();
        $this->assertSame( 1, $sent );
        $this->assertCount( 1, $stub->sent );
        $this->assertSame( 'sid', $stub->sent[0]['scan_id'] );
        $this->assertSame( 'scan_request_received', $stub->sent[0]['events'][0]['name'] );
    }

    public function test_flush_keeps_queue_on_http_failure(): void {
        $event = [
            'name' => 'optimizer_detected', 'category' => 'operational',
            'fields' => [ 'plugin' => 'rocket' ], 'scan_id' => 'sid', 'at' => 1,
        ];
        WP_Mock::userFunction( 'get_option' )->andReturn( [ $event ] );
        $captured = null;
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$captured ) {
                $captured = $value;
                return true;
            } );
        WP_Mock::userFunction( 'wp_schedule_single_event' )
            ->with( \Mockery::any(), 'aias_event_emitter_flush' )
            ->once();

        $stub = new class {
            public function emit_events( string $scan_id, array $events ): array {
                return [ 'error' => 'http_500' ];  // no `accepted` key — failure
            }
        };
        EventEmitter::set_client_for_testing( $stub );

        $sent = EventEmitter::flush();
        $this->assertSame( 0, $sent );
        $this->assertCount( 1, $captured, 'failed batch must be re-queued' );
    }

    public function test_flush_groups_events_by_scan_id(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( [
            [ 'name' => 'a', 'category' => 'operational', 'fields' => [], 'scan_id' => 'sid1', 'at' => 1 ],
            [ 'name' => 'b', 'category' => 'operational', 'fields' => [], 'scan_id' => 'sid2', 'at' => 2 ],
            [ 'name' => 'c', 'category' => 'operational', 'fields' => [], 'scan_id' => 'sid1', 'at' => 3 ],
        ] );
        WP_Mock::userFunction( 'update_option' )->once();

        $stub = new class {
            public array $calls = [];
            public function emit_events( string $scan_id, array $events ): array {
                $this->calls[] = [ 'scan_id' => $scan_id, 'count' => count( $events ) ];
                return [ 'accepted' => count( $events ), 'rejected' => 0, 'errors' => [] ];
            }
        };
        EventEmitter::set_client_for_testing( $stub );
        $sent = EventEmitter::flush();
        $this->assertSame( 3, $sent );
        $this->assertCount( 2, $stub->calls );  // one batch per scan_id
    }
}

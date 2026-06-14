<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\Outbox;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class OutboxEnqueueTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_enqueue_adopts_pending_token_as_half_state_then_deletes_transient(): void {
        $intent = [ 'urls' => [ 'https://t/a' ], 'page_count' => 1, 'user_id' => 7 ];
        WP_Mock::userFunction( 'get_option' )->andReturn( false ); // no existing entry
        WP_Mock::userFunction( 'get_transient' )->with( 'cu_scanner_pending_token_7' )->andReturn( 'PENDING-TOK' );
        $saved = null;
        WP_Mock::userFunction( 'update_option' )->once()
            ->andReturnUsing( function ( $k, $v ) use ( &$saved ) { $saved = $v; return true; } );
        WP_Mock::userFunction( 'delete_transient' )->with( 'cu_scanner_pending_token_7' )->once()->andReturn( true );
        WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );
        WP_Mock::userFunction( 'wp_schedule_single_event' )->once()->andReturn( true );

        $this->assertTrue( Outbox::enqueue( $intent ) );
        $this->assertSame( 'PENDING-TOK', $saved['job_token'] ); // ADOPTED, not null
        $this->assertSame( 'pending', $saved['status'] );
        $this->assertSame( 0, $saved['attempts'] );
    }

    public function test_enqueue_with_no_pending_token_keeps_job_token_null(): void {
        $intent = [ 'urls' => [], 'page_count' => 1, 'user_id' => 9 ];
        WP_Mock::userFunction( 'get_option' )->andReturn( false );
        WP_Mock::userFunction( 'get_transient' )->with( 'cu_scanner_pending_token_9' )->andReturn( false );
        $saved = null;
        WP_Mock::userFunction( 'update_option' )->once()
            ->andReturnUsing( function ( $k, $v ) use ( &$saved ) { $saved = $v; return true; } );
        WP_Mock::userFunction( 'delete_transient' )->with( 'cu_scanner_pending_token_9' )->once()->andReturn( true );
        WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );
        WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturn( true );

        $this->assertTrue( Outbox::enqueue( $intent ) );
        $this->assertNull( $saved['job_token'] );
    }

    public function test_enqueue_rejects_when_an_entry_already_pending(): void {
        WP_Mock::userFunction( 'get_option' )
            ->andReturn( [ 'status' => 'pending', 'intent' => [], 'attempts' => 0 ] );
        WP_Mock::userFunction( 'update_option' )->never();
        $this->assertFalse( Outbox::enqueue( [ 'urls' => [], 'user_id' => 1 ] ) );
    }
}

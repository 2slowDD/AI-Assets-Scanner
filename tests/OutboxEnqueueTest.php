<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\Outbox;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class OutboxEnqueueTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_enqueue_stores_entry_schedules_cron_and_deletes_pending_token(): void {
        $intent = [ 'urls' => [ 'https://t/a' ], 'page_count' => 1, 'user_id' => 7 ];
        WP_Mock::userFunction( 'get_option' )->andReturn( false ); // no existing entry
        $saved = null;
        WP_Mock::userFunction( 'update_option' )->once()
            ->andReturnUsing( function ( $k, $v ) use ( &$saved ) { $saved = $v; return true; } );
        WP_Mock::userFunction( 'delete_transient' )->with( 'cu_scanner_pending_token_7' )->once()->andReturn( true );
        WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );
        WP_Mock::userFunction( 'wp_schedule_single_event' )->once()->andReturn( true );

        $ok = Outbox::enqueue( $intent );
        $this->assertTrue( $ok );
        $this->assertSame( 'pending', $saved['status'] );
        $this->assertSame( 0, $saved['attempts'] );
        $this->assertSame( 7, $saved['intent']['user_id'] );
        $this->assertNull( $saved['job_token'] );
    }

    public function test_enqueue_rejects_when_an_entry_already_pending(): void {
        WP_Mock::userFunction( 'get_option' )
            ->andReturn( [ 'status' => 'pending', 'intent' => [], 'attempts' => 0 ] );
        WP_Mock::userFunction( 'update_option' )->never();
        $this->assertFalse( Outbox::enqueue( [ 'urls' => [], 'user_id' => 1 ] ) );
    }
}

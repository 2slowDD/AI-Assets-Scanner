<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\Outbox;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class OutboxLockBackoffTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_acquire_lock_succeeds_when_absent(): void {
        WP_Mock::userFunction( 'add_option' )->once()->andReturn( true );
        $this->assertTrue( Outbox::acquire_lock() );
    }

    public function test_acquire_lock_bails_when_fresh_lock_held(): void {
        WP_Mock::userFunction( 'add_option' )->andReturn( false );      // already exists
        WP_Mock::userFunction( 'get_option' )->with( Outbox::LOCK_KEY )->andReturn( time() ); // fresh
        $this->assertFalse( Outbox::acquire_lock() );
    }

    public function test_stale_lock_is_taken_over(): void {
        WP_Mock::userFunction( 'add_option' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )->with( Outbox::LOCK_KEY )->andReturn( time() - 999 ); // stale (> LOCK_TTL)
        WP_Mock::userFunction( 'update_option' )->once()->andReturn( true ); // take over
        $this->assertTrue( Outbox::acquire_lock() );
    }

    public function test_next_delay_grows_and_caps(): void {
        $this->assertSame( 30,   Outbox::next_delay( 0 ) );
        $this->assertSame( 60,   Outbox::next_delay( 1 ) );
        $this->assertSame( 120,  Outbox::next_delay( 2 ) );
        $this->assertSame( 3600, Outbox::next_delay( 20 ) ); // capped at MAX_BACKOFF
    }
}

<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\Outbox;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Phase O Task 8 — Outbox::outbox_state_for_user() done-handoff state contract (§10.7).
 */
class OutboxStateTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_state_dispatched_when_option_gone_and_job_transient_present(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( false ); // no outbox entry
        WP_Mock::userFunction( 'get_transient' )->with( 'cu_scanner_job_3' )
            ->andReturn( [ 'job_id' => 'J', 'job_token' => 'T', 'railway_url' => 'R' ] );
        $s = Outbox::outbox_state_for_user( 3 );
        $this->assertSame( 'dispatched', $s['state'] );
        $this->assertSame( 'J', $s['job_id'] );
        $this->assertSame( 'T', $s['job_token'] );
        $this->assertSame( 'R', $s['railway_url'] );
    }

    public function test_state_none_when_nothing_queued(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( false );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        $this->assertSame( 'none', Outbox::outbox_state_for_user( 3 )['state'] );
    }

    public function test_state_queued_when_pending(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( [ 'status' => 'pending', 'next_attempt_at' => 1234 ] );
        $s = Outbox::outbox_state_for_user( 3 );
        $this->assertSame( 'queued', $s['state'] );
        $this->assertSame( 1234, $s['next_attempt_at'] );
    }

    public function test_state_failed_surfaces_message(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( [ 'status' => 'failed', 'last_error' => 'boom' ] );
        $s = Outbox::outbox_state_for_user( 3 );
        $this->assertSame( 'failed', $s['state'] );
        $this->assertSame( 'boom', $s['message'] );
    }

    public function test_state_queued_takes_priority_over_job_transient(): void {
        // When outbox is pending AND a stale job transient exists, 'queued' wins.
        WP_Mock::userFunction( 'get_option' )->andReturn( [ 'status' => 'pending', 'next_attempt_at' => 9999 ] );
        // get_transient should NOT be called (outbox branch short-circuits).
        $s = Outbox::outbox_state_for_user( 5 );
        $this->assertSame( 'queued', $s['state'] );
        $this->assertSame( 9999, $s['next_attempt_at'] );
    }

    public function test_state_dispatched_when_job_transient_missing_optional_fields(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( false );
        WP_Mock::userFunction( 'get_transient' )->with( 'cu_scanner_job_7' )
            ->andReturn( [ 'job_id' => 'X' ] ); // job_token + railway_url absent
        $s = Outbox::outbox_state_for_user( 7 );
        $this->assertSame( 'dispatched', $s['state'] );
        $this->assertSame( 'X', $s['job_id'] );
        $this->assertSame( '', $s['job_token'] );
        $this->assertSame( '', $s['railway_url'] );
    }
}

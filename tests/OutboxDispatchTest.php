<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\Outbox;
use CUScanner\Api\HttpException;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Phase O Task 6 — Outbox::dispatch() chain.
 *
 * All I/O collaborators are injected via the $deps seam so tests never touch HTTP.
 * Only WP core functions are mocked (get_option / update_option / add_option /
 * delete_option / wp_clear_scheduled_hook / set_transient / scheduling).
 */
class OutboxDispatchTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    /** A due, fresh, pending entry. */
    private function pendingEntry(): array {
        return [
            'status'          => 'pending',
            'attempts'        => 0,
            'created_at'      => time(),
            'next_attempt_at' => 0,
            'job_token'       => null,
            'last_error'      => '',
            'intent'          => [
                'user_id'               => 7,
                'page_count'            => 1,
                'extra_time_count'      => 0,
                'class_c_consent_given' => '',
                'urls'                  => [ 'https://t/a' ],
            ],
        ];
    }

    /**
     * Build a full set of happy-path deps; override individual keys per test.
     * Each spy records call counts/args via the $log array passed by reference.
     */
    private function deps( array $overrides, array &$log ): array {
        $log = [
            'clear_bypass' => 0, 'release' => [], 'resolve_endpoint' => 0,
            'reserve' => 0, 'submit' => 0, 'build_payload' => 0,
            'consent_payload' => 0, 'side_effects' => [],
        ];
        $base = [
            'clear_bypass'     => function () use ( &$log ) { $log['clear_bypass']++; },
            'build_payload'    => function ( $intent ) use ( &$log ) {
                $log['build_payload']++;
                return [ [ 'urls' => $intent['urls'] ], [ [ 'class' => 'A' ] ], 'BYPASS' ];
            },
            'consent_payload'  => function ( $dt, $consent ) use ( &$log ) { $log['consent_payload']++; return null; },
            'reserve'          => function ( $pages, $et ) use ( &$log ) { $log['reserve']++; return 'TOKEN-1'; },
            'resolve_endpoint' => function () use ( &$log ) { $log['resolve_endpoint']++; return 'https://worker.example'; },
            'submit'           => function ( $url, $payload ) use ( &$log ) { $log['submit']++; return [ 'job_id' => 'JOB-9' ]; },
            'side_effects'     => function ( $result, $intent, $dt, $bt, $url, $tok, $uid ) use ( &$log ) {
                $log['side_effects'][] = compact( 'result', 'bt', 'url', 'tok', 'uid' );
                return [ 'job_id' => $result['job_id'] ?? '', 'job_token' => $tok, 'railway_url' => $url ];
            },
            'release'          => function ( $token ) use ( &$log ) { $log['release'][] = $token; },
        ];
        return array_merge( $base, $overrides );
    }

    /** Lock acquisition + release for a run that takes the lock. */
    private function expectLock(): void {
        WP_Mock::userFunction( 'add_option' )->andReturn( true ); // acquire_lock NX wins
        WP_Mock::userFunction( 'delete_option' )->andReturn( true ); // release_lock + done()
    }

    // --- 1. happy replay -----------------------------------------------------

    public function test_happy_replay_dispatches_and_marks_done(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( $this->pendingEntry() );
        WP_Mock::userFunction( 'update_option' )->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( true );
        $this->expectLock();

        $log = [];
        $deps = $this->deps( [], $log );
        $out  = Outbox::dispatch( $deps );

        $this->assertSame( 'done', $out );
        $this->assertCount( 1, $log['side_effects'] );
        $this->assertSame( 7, $log['side_effects'][0]['uid'] ); // intent user_id, not current user
    }

    // --- 2. AC-O-3 half-state: reserve succeeded, submit throws retryable -----

    public function test_half_state_persists_token_then_releases_on_second_pass(): void {
        // First pass: submit throws a retryable 503 AFTER reserve persisted the token.
        $entry = $this->pendingEntry();
        WP_Mock::userFunction( 'get_option' )->andReturn( $entry );
        $saved = null;
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $k, $v ) use ( &$saved ) { $saved = $v; return true; } );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( true );
        WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );
        WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturn( true );
        $this->expectLock();

        $log = [];
        $deps = $this->deps( [
            'submit' => function () use ( &$log ) { $log['submit']++; throw new HttpException( 'unavailable', 503 ); },
        ], $log );

        $out = Outbox::dispatch( $deps );
        $this->assertSame( 'pending', $out );
        $this->assertSame( 'TOKEN-1', $saved['job_token'], 'job_token checkpointed before submit' );

        // Second pass: entry now carries job_token -> dispatch must release it once before re-reserving.
        $entry2 = $this->pendingEntry();
        $entry2['job_token'] = 'TOKEN-1';
        WP_Mock::tearDown(); WP_Mock::setUp();
        WP_Mock::userFunction( 'get_option' )->andReturn( $entry2 );
        WP_Mock::userFunction( 'update_option' )->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( true );
        $this->expectLock();

        $log2 = [];
        $deps2 = $this->deps( [], $log2 );
        $out2 = Outbox::dispatch( $deps2 );
        $this->assertSame( 'done', $out2 );
        $this->assertSame( [ 'TOKEN-1' ], $log2['release'], 'stale token released exactly once' );
        $this->assertSame( 1, $log2['reserve'], 're-reserved after releasing stale token' );
    }

    // --- 3. AC-O-4 409 terminal ----------------------------------------------

    public function test_409_is_terminal_failure_and_releases_reservation(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( $this->pendingEntry() );
        WP_Mock::userFunction( 'update_option' )->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( true );
        $this->expectLock();

        $log = [];
        $deps = $this->deps( [
            'submit' => function () use ( &$log ) { $log['submit']++; throw new HttpException( 'busy', 409 ); },
        ], $log );

        $out = Outbox::dispatch( $deps );
        $this->assertSame( 'failed', $out );
        $this->assertSame( [ 'TOKEN-1' ], $log['release'], 'fail() released the reservation once' );
        $this->assertCount( 0, $log['side_effects'], 'no side-effects on 409' );
    }

    // --- 4. AC-O-CLASSC ------------------------------------------------------

    public function test_class_c_consent_satisfied_runs_to_done(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( $this->pendingEntry() );
        WP_Mock::userFunction( 'update_option' )->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( true );
        $this->expectLock();

        $log = [];
        $deps = $this->deps( [
            'consent_payload' => function () use ( &$log ) { $log['consent_payload']++; return null; },
        ], $log );

        $out = Outbox::dispatch( $deps );
        $this->assertSame( 'done', $out );
        $this->assertCount( 1, $log['side_effects'] );
    }

    public function test_class_c_consent_missing_fails_before_reserve(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( $this->pendingEntry() );
        WP_Mock::userFunction( 'update_option' )->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( true );
        $this->expectLock();

        $log = [];
        $deps = $this->deps( [
            'consent_payload' => function () use ( &$log ) { $log['consent_payload']++; return [ [ 'slug' => 'x' ] ]; },
        ], $log );

        $out = Outbox::dispatch( $deps );
        $this->assertSame( 'failed', $out );
        $this->assertSame( 0, $log['reserve'], 'reserve never called' );
        $this->assertSame( 0, $log['submit'], 'submit never called' );
        $this->assertSame( 0, $log['resolve_endpoint'], 'resolve_endpoint never called' );
        $this->assertSame( [], $log['release'], 'no token to release (none reserved)' );
    }

    // --- 5. §10.6 orphan safety net ------------------------------------------

    public function test_side_effects_throw_parks_orphan_transient_without_release(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( $this->pendingEntry() );
        WP_Mock::userFunction( 'update_option' )->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( true );
        $this->expectLock();

        $captured = null;
        WP_Mock::userFunction( 'set_transient' )
            ->with( 'cu_scanner_job_7', \WP_Mock\Functions::type( 'array' ), 7200 )
            ->once()
            ->andReturnUsing( function ( $k, $v, $ttl ) use ( &$captured ) { $captured = $v; return true; } );

        $log = [];
        $deps = $this->deps( [
            'side_effects' => function () use ( &$log ) {
                $log['side_effects'][] = 'attempted';
                throw new \RuntimeException( 'begin() failed' );
            },
        ], $log );

        $out = Outbox::dispatch( $deps );
        $this->assertSame( 'failed', $out );
        $this->assertSame( [], $log['release'], '§10.6: job already created+charged -> NEVER release' );
        $this->assertNotEmpty( $captured['railway_url'], 'orphan transient carries non-empty railway_url' );
        $this->assertSame( 'JOB-9', $captured['job_id'] );
        $this->assertSame( 'TOKEN-1', $captured['job_token'] );
    }

    // --- 6. M1 railway_url threading -----------------------------------------

    public function test_resolved_railway_url_is_threaded_to_side_effects(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( $this->pendingEntry() );
        WP_Mock::userFunction( 'update_option' )->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( true );
        $this->expectLock();

        $log = [];
        $deps = $this->deps( [], $log );
        $out  = Outbox::dispatch( $deps );

        $this->assertSame( 'done', $out );
        $this->assertSame( 'https://worker.example', $log['side_effects'][0]['url'] );
    }

    // --- 7. due-guard --------------------------------------------------------

    public function test_due_guard_returns_pending_without_taking_lock(): void {
        $entry = $this->pendingEntry();
        $entry['next_attempt_at'] = time() + 9999; // not yet due
        WP_Mock::userFunction( 'get_option' )->andReturn( $entry );
        WP_Mock::userFunction( 'add_option' )->never(); // lock NEVER taken

        $log = [];
        $deps = $this->deps( [], $log );
        $out  = Outbox::dispatch( $deps );

        $this->assertSame( 'pending', $out );
        $this->assertSame( 0, $log['reserve'] );
    }

    // --- 8. AC-O-6 horizon release -------------------------------------------

    public function test_horizon_exceeded_fails_and_releases_existing_token(): void {
        $entry = $this->pendingEntry();
        $entry['created_at'] = time() - 99999; // past HORIZON (86400)
        $entry['job_token']  = 'TOK';
        WP_Mock::userFunction( 'get_option' )->andReturn( $entry );
        WP_Mock::userFunction( 'update_option' )->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( true );
        $this->expectLock();

        $log = [];
        $deps = $this->deps( [], $log );
        $out  = Outbox::dispatch( $deps );

        $this->assertSame( 'failed', $out );
        $this->assertSame( [ 'TOK' ], $log['release'], 'horizon fail() released the stranded token' );
        $this->assertSame( 0, $log['reserve'], 'never reached reserve' );
    }

    // --- 9. FU-OUTBOX-ADOPT-RESERVE: best-effort step-3 release ---------------

    public function test_step3_release_is_best_effort_when_adopted_token_already_released(): void {
        $entry = $this->pendingEntry();
        $entry['job_token'] = 'ADOPTED-TOK';
        WP_Mock::userFunction( 'get_option' )->andReturn( $entry );
        WP_Mock::userFunction( 'add_option' )->andReturn( true );
        WP_Mock::userFunction( 'update_option' )->andReturn( true );
        WP_Mock::userFunction( 'delete_option' )->andReturn( true );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( true );
        $reserved = false;
        $deps = [
            'clear_bypass'     => fn() => null,
            'release'          => function () { throw new \CUScanner\Api\HttpException( 'HTTP 409: token_already_used', 409 ); },
            'build_payload'    => fn( $i ) => [ [ 'pages' => [] ], [], 'BT' ],
            'consent_payload'  => fn( $dt, $c ) => null,
            'reserve'          => function ( $p, $et ) use ( &$reserved ) { $reserved = true; return 'NEWTOK'; },
            'resolve_endpoint' => fn() => 'https://worker.example',
            'submit'           => fn( $u, $p ) => [ 'job_id' => 'J1' ],
            'side_effects'     => fn( ...$a ) => [ 'job_id' => 'J1' ],
        ];
        $status = Outbox::dispatch( $deps );
        $this->assertSame( 'done', $status ); // did NOT abort on the release throw
        $this->assertTrue( $reserved );       // proceeded to reserve a fresh token
    }
}

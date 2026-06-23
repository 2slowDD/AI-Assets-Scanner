<?php
namespace CUScanner\Tests;

use CUScanner\MenuBadge;
use CUScanner\ScanHistory;
use Mockery;
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

    // --- AC-MB-10: queued-only history (in-flight scan) ---

    public function test_only_queued_scans_returns_null(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [ 'job_id' => 'aaa', 'status' => 'queued' ] ] );

        $badge = new MenuBadge();
        $this->assertNull( $badge->get_badge_state() );
    }

    // --- AC-MB-2 + AC-MB-5: complete-unseen → green ---

    public function test_complete_unseen_returns_green(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [ 'job_id' => 'newjobid', 'status' => 'complete' ] ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( '' );

        $badge = new MenuBadge();
        $this->assertSame( 'green', $badge->get_badge_state() );
    }

    // --- AC-MB-7: failed-unseen → red ---

    public function test_failed_unseen_returns_red(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [ 'job_id' => 'failed1', 'status' => 'failed' ] ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( '' );

        $badge = new MenuBadge();
        $this->assertSame( 'red', $badge->get_badge_state() );
    }

    // --- AC-MB-3 + AC-MB-4: complete-seen → null ---

    public function test_complete_seen_returns_null(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [ 'job_id' => 'samejobid', 'status' => 'complete' ] ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( 'samejobid' );

        $badge = new MenuBadge();
        $this->assertNull( $badge->get_badge_state() );
    }

    // --- AC-MB-8: most-recent (newest) wins regardless of older states ---

    public function test_complete_after_failed_returns_green(): void {
        // History is newest-first (ScanHistory::create_record uses array_unshift).
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [
                [ 'job_id' => 'newest', 'status' => 'complete' ],
                [ 'job_id' => 'older',  'status' => 'failed' ],
            ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( '' );

        $badge = new MenuBadge();
        $this->assertSame( 'green', $badge->get_badge_state() );
    }

    // --- AC-MB-11: cancelled does NOT trigger badge ---

    public function test_cancelled_only_returns_null(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [ 'job_id' => 'cancelled1', 'status' => 'cancelled' ] ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( '' );

        $badge = new MenuBadge();
        $this->assertNull( $badge->get_badge_state() );
    }

    // --- AC-MB-12: cancelled between two badge-triggering scans doesn't break the walk ---

    public function test_cancelled_between_complete_records_walks_past(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [
                [ 'job_id' => 'newest',    'status' => 'complete' ],
                [ 'job_id' => 'cancelled', 'status' => 'cancelled' ],
                [ 'job_id' => 'older',     'status' => 'complete' ],
            ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( '' );

        $badge = new MenuBadge();
        $this->assertSame( 'green', $badge->get_badge_state() );
    }

    public function test_cancelled_blocks_no_walks_to_older_failed(): void {
        // Newest is cancelled (non-triggering); next is failed (triggers red).
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [
                [ 'job_id' => 'cancelled1', 'status' => 'cancelled' ],
                [ 'job_id' => 'older_fail', 'status' => 'failed' ],
            ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( '' );

        $badge = new MenuBadge();
        $this->assertSame( 'red', $badge->get_badge_state() );
    }

    // --- AC-MB-3 (mark seen) ---

    public function test_mark_seen_updates_option_when_changed(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [ 'job_id' => 'latest', 'status' => 'complete' ] ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( 'older' );
        WP_Mock::userFunction( 'update_option' )
            ->once()
            ->with( 'aias_last_seen_scan_id', 'latest' );

        $badge = new MenuBadge();
        $badge->mark_seen_on_main_page();
    }

    // --- Minor 6: skip redundant update when value unchanged ---

    public function test_mark_seen_skips_update_when_unchanged(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [ [ 'job_id' => 'same', 'status' => 'complete' ] ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_last_seen_scan_id', '' )
            ->andReturn( 'same' );
        // update_option must NOT be called.
        WP_Mock::userFunction( 'update_option' )->times( 0 );

        $badge = new MenuBadge();
        $badge->mark_seen_on_main_page();
    }

    // --- 1.4.5: server-side scan-completion polling on Heartbeat ---
    //
    // These tests verify check_active_job_completion's early-return paths via
    // filter_heartbeat (which calls it before returning the badge state). If
    // either early return fails to fire, the production code would instantiate
    // RailwayClient + Settings, which would error out in the test context
    // (no WP DB, invalid URL allowlist) — making the test fail loudly. So a
    // passing test confirms the early-return is correctly short-circuiting.

    public function test_filter_heartbeat_no_transient_skips_railway_poll(): void {
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
        WP_Mock::userFunction( 'get_transient' )
            ->with( 'cu_scanner_job_0' )
            ->andReturn( false );
        // Badge-state path still runs after the early return.
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [] );

        $badge  = new MenuBadge();
        $result = $badge->filter_heartbeat( [], [] );

        $this->assertNull( $result['aias_badge'] );
    }

    public function test_filter_heartbeat_malformed_transient_skips_railway_poll(): void {
        // Transient exists but is missing required fields (job_id absent).
        // Verifies the second early-return clause in check_active_job_completion.
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
        WP_Mock::userFunction( 'get_transient' )
            ->with( 'cu_scanner_job_0' )
            ->andReturn( [ 'job_token' => 'abc', 'railway_url' => 'https://example' ] );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_history', [] )
            ->andReturn( [] );

        $badge  = new MenuBadge();
        $result = $badge->filter_heartbeat( [], [] );

        $this->assertNull( $result['aias_badge'] );
    }

    // --- Task 5: characterization test — drives the full `complete` branch via injected seams ---

    /**
     * Build a MenuBadge whose railway() returns an anonymous stub client
     * that returns $status from get_status().
     *
     * @param array    $status  The array returned by the stub's get_status().
     * @param mixed    $ajax    Optional ScannerAjax mock (or null for default lazy-init).
     */
    private function makeBadge( array $status, $ajax = null ): MenuBadge {
        $factory = function ( $url, $key ) use ( $status ) {
            return new class( $status ) {
                private $s;
                public function __construct( $s ) { $this->s = $s; }
                public function get_status( $j, $t, $f ) { return $this->s; }
            };
        };
        return new MenuBadge( null, $ajax, $factory );
    }

    public function test_complete_status_calls_do_build_result_via_injected_ajax(): void {
        $state = [ 'job_id' => 'J', 'job_token' => 'TOK', 'bypass_token' => 'BYP', 'railway_url' => 'https://r' ];
        WP_Mock::userFunction( 'get_transient' )->andReturn( $state );
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

        $ajax = Mockery::mock( \CUScanner\Admin\ScannerAjax::class );
        $ajax->shouldReceive( 'do_build_result' )->once()->with( 'J', 'TOK' )->andReturn( [] );

        $this->makeBadge( [ 'status' => 'complete' ], $ajax )->check_active_job_completion();
        $this->assertConditionsMet();
    }

    // --- Task 7: R3 Stage C — paused branch arms Tier C rebuild cron (idempotent, stable args, user_id) ---

    public function test_paused_arms_rebuild_cron_once_with_user_id_and_stable_args(): void {
        // Use real time() — WP_Mock cannot override internal PHP functions.
        // Set resume_at 1800 s (30 min) from now, expressed as ms-epoch.
        $before       = time();
        $resume_at_ms = ( $before + 1800 ) * 1000;   // 30 min out, ms-epoch
        $state = [ 'job_id' => 'J', 'job_token' => 'TOK', 'bypass_token' => 'BYP', 'railway_url' => 'https://r' ];
        WP_Mock::userFunction( 'get_transient' )->andReturn( $state );
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );

        // Capture the actual args passed to both cron functions for post-call assertion.
        $captured_scheduled_when = null;
        $captured_scheduled_args = null;
        $captured_next_args      = null;

        WP_Mock::userFunction( 'wp_next_scheduled' )
            ->andReturnUsing( function ( $hook, $args ) use ( &$captured_next_args ) {
                $captured_next_args = $args;
                return false;   // not yet scheduled → proceed to wp_schedule_single_event
            } );

        WP_Mock::userFunction( 'wp_schedule_single_event' )
            ->once()
            ->andReturnUsing( function ( $when, $hook, $args ) use ( &$captured_scheduled_when, &$captured_scheduled_args ) {
                $captured_scheduled_when = $when;
                $captured_scheduled_args = $args;
                return true;
            } );

        $this->makeBadge( [ 'status' => 'paused', 'resume_at' => $resume_at_ms ] )->check_active_job_completion();
        $this->assertConditionsMet();

        // --- Post-call assertions ---
        $this->assertNotNull( $captured_scheduled_args, 'wp_schedule_single_event must have been called' );

        // Both cron calls must receive identical args (same outer-array, same keys + values).
        $this->assertSame( $captured_next_args, $captured_scheduled_args, 'wp_next_scheduled and wp_schedule_single_event must receive identical $args' );

        // Outer-array wrapping: args must be [ [ inner ] ].
        $this->assertCount( 1, $captured_scheduled_args, 'args must be outer-array with exactly one element' );
        $inner = $captured_scheduled_args[0];

        // Stable fields (no resume_at).
        $this->assertSame( 'J',          $inner['job_id'],      'job_id must be stable' );
        $this->assertSame( 'TOK',        $inner['job_token'],   'job_token must be stable' );
        $this->assertSame( 'https://r',  $inner['railway_url'], 'railway_url must be stable' );
        $this->assertSame( 7,            $inner['user_id'],     'user_id must be int 7' );
        $this->assertArrayNotHasKey( 'resume_at', $inner,       'resume_at must NOT be in stable args' );

        // armed_at must be a real timestamp close to $before (within 5s).
        $after = time();
        $this->assertGreaterThanOrEqual( $before, $inner['armed_at'], 'armed_at must be >= test start time' );
        $this->assertLessThanOrEqual( $after + 1, $inner['armed_at'], 'armed_at must be <= test end time + 1' );

        // $when = max(time(), ceil(resume_at_ms/1000)) + 60 ≈ ($before+1800) + 60.
        $this->assertGreaterThanOrEqual( ( $before + 1800 ) + 60, $captured_scheduled_when, '$when must be resume_at + 60' );
        $this->assertLessThanOrEqual( ( $after  + 1800 ) + 62, $captured_scheduled_when, '$when must not be unreasonably far in future' );
    }

    // --- Task 8: R3 Stage C — paused_exhausted terminal: builds partial (charged_count=X) + deletes transient ---

    public function test_paused_exhausted_builds_partial_with_charged_count_and_deletes_transient(): void {
        $state = [ 'job_id' => 'J', 'job_token' => 'TOK', 'bypass_token' => 'BYP', 'railway_url' => 'https://r' ];
        WP_Mock::userFunction( 'get_transient' )->andReturn( $state );
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
        WP_Mock::userFunction( 'delete_transient' )->once()->with( 'cu_scanner_job_7' );

        $ajax = Mockery::mock( \CUScanner\Admin\ScannerAjax::class );
        $ajax->shouldReceive( 'do_build_result' )->once()->with( 'J', 'TOK', 4 )->andReturn( [] );  // charged_count=X

        $this->makeBadge( [ 'status' => 'paused_exhausted', 'completed' => 4, 'total' => 10 ], $ajax )
             ->check_active_job_completion();
        $this->assertConditionsMet();
    }

    // --- Task 9: R3 Stage C — Tier C wp-cron handler run_r3_rebuild ---

    public function test_cron_handler_sets_user_then_builds_partial_on_terminal(): void {
        $job = [ 'job_id' => 'J', 'job_token' => 'TOK', 'railway_url' => 'https://r',
                 'user_id' => 7, 'armed_at' => 1_900_000_000 ];
        WP_Mock::userFunction( 'wp_set_current_user' )->once()->with( 7 );
        WP_Mock::userFunction( 'delete_transient' )->once()->with( 'cu_scanner_job_7' );
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->once();
        WP_Mock::userFunction( 'wp_schedule_single_event' )->never();      // terminal → no reschedule

        $ajax = Mockery::mock( \CUScanner\Admin\ScannerAjax::class );
        $ajax->shouldReceive( 'do_build_result' )->once()->with( 'J', 'TOK', 4 )->andReturn( [] );

        $this->makeBadge( [ 'status' => 'paused_exhausted', 'completed' => 4, 'total' => 10 ], $ajax )
             ->run_r3_rebuild( $job );
        $this->assertConditionsMet();
    }

    public function test_cron_handler_reschedules_while_paused(): void {
        // armed_at in the future (year ~2030) so time() - armed_at is always negative → well under ceiling.
        // WP_Mock cannot mock internal PHP time() — use armed_at > real now instead.
        $job = [ 'job_id' => 'J', 'job_token' => 'TOK', 'railway_url' => 'https://r',
                 'user_id' => 7, 'armed_at' => 1_900_000_000 ];
        WP_Mock::userFunction( 'wp_set_current_user' )->once()->with( 7 );
        WP_Mock::userFunction( 'wp_schedule_single_event' )->once();        // still paused → reschedule
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->never();

        $this->makeBadge( [ 'status' => 'paused', 'resume_at' => ( 1_900_000_500 + 900 ) * 1000 ] )
             ->run_r3_rebuild( $job );
        $this->assertConditionsMet();
    }

    // --- Task 6: R3 Stage C — paused branch refreshes job transient TTL, preserves full payload ---

    public function test_paused_refreshes_transient_ttl_preserving_full_payload(): void {
        // Use real time() — WP_Mock cannot override internal PHP functions.
        // Set resume_at 1800 s (30 min) from now, expressed as ms-epoch.
        $now          = time();
        $resume_at_ms = ( $now + 1800 ) * 1000;   // 30 min out, ms-epoch
        $state = [ 'job_id' => 'J', 'job_token' => 'TOK', 'bypass_token' => 'BYP', 'railway_url' => 'https://r' ];
        WP_Mock::userFunction( 'get_transient' )->andReturn( $state );
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
        WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( true );        // arm guard short-circuits (Task 7 adds arming)
        WP_Mock::userFunction( 'set_transient' )->once()
            ->andReturnUsing( function ( $k, $v, $ttl ) {
                $this->assertSame( 'cu_scanner_job_7', $k );
                $this->assertSame( 'BYP', $v['bypass_token'], 'full payload preserved' );
                $this->assertGreaterThanOrEqual( 1800, $ttl );
                $this->assertLessThanOrEqual( 1800 + 300 + 2, $ttl );
                return true;
            } );

        $this->makeBadge( [ 'status' => 'paused', 'resume_at' => $resume_at_ms ] )->check_active_job_completion();
        $this->assertConditionsMet();
    }

    // --- Task 10: R3 Stage C — cron handler registered UN-GATED (loads in wp-cron/front-end context) ---

    public function test_r3_rebuild_handler_registered_ungated(): void {
        // Prove the registration is OUTSIDE the is_admin() gate: with is_admin()=false
        // (the cron / front-end context), Plugin::init() must still add the cron callback,
        // otherwise the scheduled cu_scanner_r3_rebuild event would have no handler.
        WP_Mock::userFunction( 'plugin_basename' )->andReturn( 'ai-assets-scanner/ai-assets-scanner.php' );
        WP_Mock::userFunction( 'is_admin' )->andReturn( false );
        // The handler is registered as an instance callback [ new MenuBadge(), 'run_r3_rebuild' ].
        // WP_Mock keys plain-object callbacks by spl_object_hash (unpredictable), so match any
        // MenuBadge instance via its AnyInstance matcher.
        WP_Mock::expectActionAdded(
            'cu_scanner_r3_rebuild',
            [ new \WP_Mock\Matcher\AnyInstance( \CUScanner\MenuBadge::class ), 'run_r3_rebuild' ],
            10,
            1
        );

        ( new \CUScanner\Plugin() )->init();
        $this->assertConditionsMet();
    }
}

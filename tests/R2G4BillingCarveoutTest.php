<?php
// tests/R2G4BillingCarveoutTest.php
//
// Focused regression tests for Sub-spec E R2 G4 billing carve-outs:
//   • handle_failure + !$state  → release_credits IS called (pending token released)
//   • handle_failure + $state   → release_credits NOT called, ScanHistory NOT stamped
//   • cancel_job (success)      → ScanHistory NOT written; pages_completed returned
//
// Testability note: WpserviceClient, RailwayClient, ScanHistory, and BypassManager are all
// instantiated with `new` inside the method — no injection seam.  We assert at the
// underlying WP function layer:
//   release_credits  → wp_remote_post to /credits/release
//   ScanHistory      → update_option on cu_scanner_history
//   cancel success   → wp_send_json_success receives ['pages_completed' => N]
// Any branch that genuinely cannot be asserted here (e.g. job_token never sent to wire
// in the fallback path when the method simply skips) is documented inline as deferred to
// AC-R2-4 (handle_failure $state live) / AC-R2-2 (user_cancel single-writer live).

namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class R2G4BillingCarveoutTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function mockCheck(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( true );
        WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
    }

    private function mockWpParseUrl(): void {
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
            function ( string $url, ?int $component = null ) {
                $parts = parse_url( $url );
                if ( null === $component ) {
                    return $parts;
                }
                $map = [
                    PHP_URL_SCHEME => 'scheme',
                    PHP_URL_HOST   => 'host',
                    PHP_URL_PORT   => 'port',
                    PHP_URL_USER   => 'user',
                    PHP_URL_PASS   => 'pass',
                ];
                return $parts[ $map[ $component ] ?? '' ] ?? null;
            }
        );
    }

    // -------------------------------------------------------------------------
    // Test 1: handle_failure + !$state → release_credits IS called
    //
    // Verifies the MANDATORY-unchanged branch: when submit_job never ran
    // (no cu_scanner_job transient, only a pending token), AAS must release
    // the pending reservation or the account strands (lockout).
    // -------------------------------------------------------------------------

    public function test_handle_failure_no_state_releases_pending_token(): void {
        $this->mockCheck();
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 11 );

        // No cu_scanner_job transient — submit_job never ran.
        WP_Mock::userFunction( 'get_transient' )
            ->with( 'cu_scanner_job_11' )
            ->andReturn( false );

        // Pending token exists.
        WP_Mock::userFunction( 'get_transient' )
            ->with( 'cu_scanner_pending_token_11' )
            ->andReturn( 'pending-tok-abc' );

        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( 'test-api-key' );

        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://example.com' );
        $this->mockWpParseUrl();

        // release_credits fires via wp_remote_post to /credits/release — assert called once.
        $releaseUrl = null;
        $releaseBody = null;
        WP_Mock::userFunction( 'wp_remote_post' )
            ->once()
            ->andReturnUsing( function ( string $url, array $args = [] ) use ( &$releaseUrl, &$releaseBody ) {
                $releaseUrl  = $url;
                $releaseBody = $args['body'] ?? null;
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '{}',
                ];
            } );

        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{}' );

        WP_Mock::userFunction( 'delete_transient' )
            ->with( 'cu_scanner_pending_token_11' )
            ->once();

        // BypassManager::delete_all_tokens calls get_option + update_option — allow freely.
        WP_Mock::userFunction( 'get_option' )->andReturn( [] );
        WP_Mock::userFunction( 'update_option' )->andReturn( true );

        WP_Mock::userFunction( 'wp_send_json_success' )->once();

        ( new ScannerAjax() )->handle_failure();

        $this->assertConditionsMet();
        $this->assertNotNull( $releaseUrl, 'release_credits must fire via wp_remote_post' );
        $this->assertStringContainsString( '/credits/release', $releaseUrl );
    }

    // -------------------------------------------------------------------------
    // Test 2: handle_failure + $state → release_credits NOT called,
    //         ScanHistory NOT stamped 'failed'
    //
    // The $state branch is now a FALLBACK only. R1 finalises the charge and owns
    // the credit release for failed+$state jobs.  AAS must not race R1 by calling
    // release_credits, and must not write a competing ScanHistory record (do_build_result
    // owns the 'partial' record).
    //
    // Assertion strategy:
    //   • wp_remote_post never() — proves release_credits does not fire.
    //   • update_option never() with cu_scanner_history — proves ScanHistory not written.
    //     (ScanHistory::update_status reads get_option('cu_scanner_history') then calls
    //      update_option('cu_scanner_history', ...) — the update_option call is the write.)
    //   Residual: confirms R1 owns the wire-level release; deferred to AC-R2-4 live bake.
    // -------------------------------------------------------------------------

    public function test_handle_failure_with_state_does_not_release_and_does_not_stamp_failed(): void {
        $this->mockCheck();
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

        // cu_scanner_job transient is present — submit_job ran.
        WP_Mock::userFunction( 'get_transient' )
            ->with( 'cu_scanner_job_7' )
            ->andReturn( [
                'job_id'    => 'job-r2g4',
                'job_token' => 'tok-r2g4',
            ] );

        // wp_remote_post must never be called — no release_credits fire.
        WP_Mock::userFunction( 'wp_remote_post' )->never();

        // update_option for cu_scanner_history must never be called — no ScanHistory write.
        // Allow update_option for other keys (e.g. BypassManager) but not cu_scanner_history.
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( string $key ) {
                $this->assertNotSame(
                    'cu_scanner_history',
                    $key,
                    'ScanHistory::update_status must NOT be called in the $state branch — do_build_result owns the partial record'
                );
                return true;
            } );

        // BypassManager::delete_all_tokens may call get_option — allow freely.
        WP_Mock::userFunction( 'get_option' )->andReturn( [] );

        WP_Mock::userFunction( 'delete_transient' )
            ->with( 'cu_scanner_job_7' )
            ->once();

        WP_Mock::userFunction( 'wp_send_json_success' )->once();

        ( new ScannerAjax() )->handle_failure();

        $this->assertConditionsMet();
    }

    // -------------------------------------------------------------------------
    // Test 3: cancel_job (successful cancel) → ScanHistory NOT written,
    //         pages_completed returned in success payload
    //
    // do_build_result is the single write-owner for the user_cancel ScanHistory
    // record.  cancel_job must not write a competing 'cancelled' record.
    // It MUST return pages_completed so the JS can pass it to build_result.
    // -------------------------------------------------------------------------

    public function test_cancel_job_success_does_not_write_scan_history_and_returns_pages_completed(): void {
        $this->mockCheck();
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );

        WP_Mock::userFunction( 'get_transient' )
            ->with( 'cu_scanner_job_3' )
            ->andReturn( [
                'job_id'      => 'job-cancel-test',
                'job_token'   => 'tok-cancel-test',
                'railway_url' => 'https://cu-scanner-railway-production.up.railway.app',
            ] );

        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( 'test-api-key' );

        $this->mockWpParseUrl();

        // Railway cancel → success with pages_completed = 4.
        WP_Mock::userFunction( 'wp_remote_post' )
            ->once()
            ->andReturn( [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [ 'pages_completed' => 4 ] ),
            ] );

        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )
            ->andReturn( json_encode( [ 'pages_completed' => 4 ] ) );

        // update_option for cu_scanner_history must NEVER be called.
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( string $key ) {
                $this->assertNotSame(
                    'cu_scanner_history',
                    $key,
                    'cancel_job must NOT write a ScanHistory record — do_build_result owns the user_cancel partial record'
                );
                return true;
            } );

        // BypassManager may call get_option — allow freely.
        WP_Mock::userFunction( 'get_option' )->andReturn( [] );

        WP_Mock::userFunction( 'delete_transient' )
            ->with( 'cu_scanner_job_3' )
            ->once();

        // Success payload must include pages_completed = 4.
        $captured = null;
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->once()
            ->andReturnUsing( function ( $data ) use ( &$captured ) {
                $captured = $data;
            } );

        ( new ScannerAjax() )->cancel_job();

        $this->assertConditionsMet();
        $this->assertIsArray( $captured, 'wp_send_json_success must be called with an array payload' );
        $this->assertArrayHasKey( 'pages_completed', $captured, 'pages_completed key must be present in success payload' );
        $this->assertSame( 4, $captured['pages_completed'], 'pages_completed must equal the value returned by Railway' );
    }
}

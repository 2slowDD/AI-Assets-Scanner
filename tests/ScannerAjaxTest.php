<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class ScannerAjaxTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    private function mockCheck(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( true );
        WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
    }

    public function test_check_job_returns_error_when_no_transient(): void {
        $this->mockCheck();
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        WP_Mock::userFunction( 'get_transient' )
            ->with( 'cu_scanner_job_1' )
            ->andReturn( false );
        WP_Mock::userFunction( 'wp_send_json_error' )
            ->once()
            ->with( 'No active job' );

        ( new ScannerAjax() )->check_job();
        $this->assertConditionsMet();
    }

    public function test_check_job_returns_job_data_when_transient_exists(): void {
        $this->mockCheck();
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        WP_Mock::userFunction( 'get_transient' )
            ->with( 'cu_scanner_job_1' )
            ->andReturn( [
                'job_id'       => 'abc123',
                'job_token'    => 'tok456',
                'railway_url'  => 'https://cu-scanner-railway-production.up.railway.app',
                'bypass_token' => 'byp789',
            ] );
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->once()
            ->with( [
                'job_id'      => 'abc123',
                'job_token'   => 'tok456',
                'railway_url' => 'https://cu-scanner-railway-production.up.railway.app',
            ] );

        ( new ScannerAjax() )->check_job();
        $this->assertConditionsMet();
    }

    public function test_detect_plugins_returns_null_balance_when_api_fails(): void {
        $this->mockCheck();
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )->andReturn( 'test-key' );
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://example.com' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturn( 'example.com' );
        WP_Mock::userFunction( 'wp_remote_get' )
            ->andReturn( new \WP_Error( 'http_failure', 'Connection refused' ) );

        $captured = null;
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->once()
            ->andReturnUsing( function ( $data ) use ( &$captured ) { $captured = $data; } );

        ( new ScannerAjax() )->detect_plugins();
        $this->assertConditionsMet();
        $this->assertArrayHasKey( 'balance', $captured );
        $this->assertNull( $captured['balance'] );
    }

    public function test_detect_plugins_returns_balance_when_api_succeeds(): void {
        $this->mockCheck();
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )->andReturn( 'test-key' );
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://example.com' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturn( 'example.com' );
        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [ 'response' => [ 'code' => 200 ] ] );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{"balance":42}' );

        $captured = null;
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->once()
            ->andReturnUsing( function ( $data ) use ( &$captured ) { $captured = $data; } );

        ( new ScannerAjax() )->detect_plugins();
        $this->assertConditionsMet();
        $this->assertArrayHasKey( 'balance', $captured );
        $this->assertSame( 42, $captured['balance'] );
    }

    public function test_reserve_job_hydrates_missing_railway_url_before_reserving(): void {
        $this->mockCheck();
        $_POST['page_count'] = '1';

        WP_Mock::userFunction( 'absint' )->andReturnUsing( function ( $value ) {
            return max( 0, (int) $value );
        } );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( 'cusk_Freekey_10' );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_railway_url', '' )
            ->andReturn( '' );
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://www.example.com' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( string $url, ?int $component = null ) {
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
        } );
        WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing( function ( string $url ) {
            if ( str_contains( $url, '/cu-scanner/v1/auth' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '{"railway_url":"https://cu-scanner-railway-production.up.railway.app","balance":3}',
                ];
            }
            if ( str_contains( $url, '/cu-scanner/v1/jobs/reserve' ) ) {
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '{"job_token":"plain-token","balance_after":2}',
                ];
            }
            return [ 'response' => [ 'code' => 404 ], 'body' => '{"message":"not found"}' ];
        } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( function ( array $response ) {
            return (int) ( $response['response']['code'] ?? 0 );
        } );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( function ( array $response ) {
            return (string) ( $response['body'] ?? '' );
        } );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_railway_url', 'https://cu-scanner-railway-production.up.railway.app' )
            ->once();
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 11 );
        WP_Mock::userFunction( 'set_transient' )
            ->with( 'cu_scanner_pending_token_11', 'plain-token', 3600 )
            ->once();

        $captured = null;
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->once()
            ->andReturnUsing( function ( $data ) use ( &$captured ) { $captured = $data; } );

        ( new ScannerAjax() )->reserve_job();

        unset( $_POST['page_count'] );
        $this->assertConditionsMet();
        $this->assertSame( [ 'reserved' => true, 'job_token' => 'plain-token' ], $captured );
    }

    public function test_reserve_job_forwards_extra_time_count_from_post_to_reserve_body(): void {
        // FU-AAS-EXTRA-TIME — end-to-end: the AJAX handler must read
        // $_POST['extra_time_count'] and forward it through WpserviceClient into
        // the SaaS /jobs/reserve POST body (the "M" of the N+M reserve gate).
        $this->mockCheck();
        $_POST['page_count']       = '4';
        $_POST['extra_time_count'] = '2';

        WP_Mock::userFunction( 'absint' )->andReturnUsing( function ( $value ) {
            return max( 0, (int) $value );
        } );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( 'cusk_Freekey_10' );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_railway_url', '' )
            ->andReturn( 'https://cu-scanner-railway-production.up.railway.app' );
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://www.example.com' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( string $url, ?int $component = null ) {
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
        } );

        $reserve_body = null;
        WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing( function ( string $url, array $args ) use ( &$reserve_body ) {
            if ( str_contains( $url, '/cu-scanner/v1/jobs/reserve' ) ) {
                $reserve_body = json_decode( $args['body'], true );
                return [
                    'response' => [ 'code' => 200 ],
                    'body'     => '{"job_token":"plain-token","balance_after":2}',
                ];
            }
            return [ 'response' => [ 'code' => 404 ], 'body' => '{"message":"not found"}' ];
        } );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( function ( array $response ) {
            return (int) ( $response['response']['code'] ?? 0 );
        } );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing( function ( array $response ) {
            return (string) ( $response['body'] ?? '' );
        } );
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 11 );
        WP_Mock::userFunction( 'set_transient' )
            ->with( 'cu_scanner_pending_token_11', 'plain-token', 3600 )
            ->once();
        WP_Mock::userFunction( 'wp_send_json_success' )->once();

        ( new ScannerAjax() )->reserve_job();

        unset( $_POST['page_count'], $_POST['extra_time_count'] );
        $this->assertConditionsMet();
        $this->assertIsArray( $reserve_body );
        $this->assertSame( 4, $reserve_body['page_count'] );
        $this->assertArrayHasKey( 'extra_time_count', $reserve_body );
        $this->assertSame( 2, $reserve_body['extra_time_count'] );
    }

    public function test_format_submit_error_detail_short_message_is_untruncated(): void {
        $result = ScannerAjax::format_submit_error_detail( 'Railway HTTP 401: no such token' );
        $this->assertSame( 'Scan submission failed: Railway HTTP 401: no such token', $result );
    }

    public function test_format_submit_error_detail_truncates_at_80_chars_with_ellipsis(): void {
        $long   = str_repeat( 'x', 200 );
        $result = ScannerAjax::format_submit_error_detail( $long );

        // Prefix is fixed literal text
        $this->assertStringStartsWith( 'Scan submission failed: ', $result );

        // Detail portion = first 80 chars of input + ellipsis
        $detail = mb_substr( $result, mb_strlen( 'Scan submission failed: ' ) );
        $this->assertSame( str_repeat( 'x', 80 ) . '…', $detail );
    }

    public function test_format_submit_error_detail_at_exactly_80_chars_no_ellipsis(): void {
        $exact  = str_repeat( 'x', 80 );
        $result = ScannerAjax::format_submit_error_detail( $exact );
        $this->assertSame( 'Scan submission failed: ' . $exact, $result );
        $this->assertStringEndsNotWith( '…', $result );
    }

    public function test_format_reserve_error_detail_short_message_is_untruncated(): void {
        $result = ScannerAjax::format_reserve_error_detail( 'HTTP 429: rate limited' );
        $this->assertSame( 'Could not reserve credits: HTTP 429: rate limited', $result );
    }

    public function test_format_reserve_error_detail_truncates_at_80_chars_with_ellipsis(): void {
        $long   = str_repeat( 'y', 200 );
        $result = ScannerAjax::format_reserve_error_detail( $long );

        $this->assertStringStartsWith( 'Could not reserve credits: ', $result );

        $detail = mb_substr( $result, mb_strlen( 'Could not reserve credits: ' ) );
        $this->assertSame( str_repeat( 'y', 80 ) . '…', $detail );
    }

    public function test_format_reserve_error_detail_at_exactly_80_chars_no_ellipsis(): void {
        $exact  = str_repeat( 'y', 80 );
        $result = ScannerAjax::format_reserve_error_detail( $exact );
        $this->assertSame( 'Could not reserve credits: ' . $exact, $result );
        $this->assertStringEndsNotWith( '…', $result );
    }

    public function test_billable_count_excludes_origin_unavailable(): void {
        $pages = [
            [ 'status' => 'done' ],
            [ 'status' => 'done' ],
            [ 'status' => 'origin_unavailable' ],   // skipped — must NOT bill
            [ 'status' => 'error' ],                 // already excluded
        ];
        $this->assertSame( 2, ScannerAjax::billable_page_count( $pages ) );
    }

    // FU-AAS-EXTRA-TIME (UI Task 4) — per-URL extra_time flag threaded into the job payload.

    public function test_build_pages_array_threads_extra_time_flag(): void {
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( string $url, ?int $component = null ) {
            $parts = parse_url( $url );
            return ( PHP_URL_HOST === $component ) ? ( $parts['host'] ?? null ) : $parts;
        } );

        $et_set = array_flip( [ 'https://x/b' ] );
        $pages  = ScannerAjax::__test_build_pages_array(
            [ 'https://x/a', 'https://x/b' ], [], [], 'https://x/', $et_set
        );

        $this->assertFalse( $pages[0]['extra_time'], 'URL not in ET set → false' );
        $this->assertTrue(  $pages[1]['extra_time'], 'URL in ET set → true' );
    }

    public function test_ratchet_enabled_defaults_on_in_beta(): void {
        // Default-ON (beta): absent option → get_option returns the `true` default.
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_ratchet_enabled', true )
            ->andReturn( true );

        $this->assertTrue( ( new ScannerAjax() )->__test_ratchet_enabled() );
        $this->assertConditionsMet();
    }

    public function test_ratchet_enabled_opt_out_when_option_false(): void {
        // Opt-out kill switch: option set to a falsy value → disabled.
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_ratchet_enabled', true )
            ->andReturn( false );

        $this->assertFalse( ( new ScannerAjax() )->__test_ratchet_enabled() );
        $this->assertConditionsMet();
    }

    /**
     * The reshaping array_map in submit_job() hardcodes a fixed key set
     * (url/bypass_token/bypass_suffixes). An extra_time key added only in
     * build_pages_array() is SILENTLY DROPPED there unless the map carries it.
     * This test is RED until the reshape carries extra_time through.
     */

    public function test_reshape_page_specs_carries_extra_time_through(): void {
        $specs = [
            [ 'url' => 'https://x/a', 'bypass_suffixes' => [], 'extra_time' => false ],
            [ 'url' => 'https://x/b', 'bypass_suffixes' => [], 'extra_time' => true ],
        ];
        $build_scan_url = static fn( string $u, array $s ): string => $u;
        $pages = ScannerAjax::__test_reshape_page_specs( $specs, $build_scan_url, 'tok' );

        $this->assertArrayHasKey( 'extra_time', $pages[0], 'reshape must carry extra_time' );
        $this->assertFalse( $pages[0]['extra_time'] );
        $this->assertTrue(  $pages[1]['extra_time'] );
        // Existing keys preserved.
        $this->assertSame( 'https://x/a', $pages[0]['url'] );
        $this->assertSame( 'tok', $pages[0]['bypass_token'] );
        $this->assertSame( [], $pages[0]['bypass_suffixes'] );
    }

    // ─────────────────────────────────────────────────────────────────────
    // ET Result Ratchet — B2 + B3
    // ─────────────────────────────────────────────────────────────────────

    /**
     * B2 — non-ET scan: persist_r_orig calls set_transient once with the
     * correct key, rule-key array, and HOUR_IN_SECONDS TTL.
     */
    public function test_b2_persist_r_orig_calls_set_transient_on_non_et_scan(): void {
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( string $url, ?int $component = null ) {
            $parts = parse_url( $url );
            if ( null === $component ) { return $parts; }
            $map = [ PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PATH => 'path' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

        $cu_json = [
            'rules' => [
                [
                    'url_pattern'  => 'https://s.com/p',
                    'asset_handle' => 'h',
                    'asset_type'   => 'css',
                    'device_type'  => 'all',
                    'group_id'     => 2,
                    'match_type'   => 'exact',
                    'source_label' => 'AA Scanner',
                ],
            ],
            'by_page' => [],
        ];
        $pages_raw = [
            [ 'url' => 'https://s.com/p', 'status' => 'done', 'assets' => [] ],
        ];

        $captured_key  = null;
        $captured_data = null;
        $captured_ttl  = null;
        WP_Mock::userFunction( 'set_transient' )
            ->once()
            ->andReturnUsing( function ( $key, $data, $ttl ) use ( &$captured_key, &$captured_data, &$captured_ttl ) {
                $captured_key  = $key;
                $captured_data = $data;
                $captured_ttl  = $ttl;
            } );

        ( new ScannerAjax() )->__test_persist_r_orig( $cu_json, $pages_raw );
        $this->assertConditionsMet();

        $this->assertSame( 'cu_scanner_r_orig_7', $captured_key );
        $this->assertSame( HOUR_IN_SECONDS, $captured_ttl );
        $this->assertIsArray( $captured_data );
        $this->assertArrayHasKey( 'urls', $captured_data );
        $this->assertArrayHasKey( 'rules', $captured_data );
        $this->assertContains( 'https://s.com/p', $captured_data['urls'] );
        $this->assertCount( 1, $captured_data['rules'] );
        $this->assertSame( 'h', $captured_data['rules'][0]['asset_handle'] );
    }

    /**
     * B2 — ratchet_enabled=false: __test_should_persist_r_orig returns false,
     * so persist_r_orig (and therefore set_transient) must never be called.
     */
    public function test_b2_no_persist_when_ratchet_disabled(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_ratchet_enabled', true )
            ->andReturn( false );

        $pages_raw = [
            [ 'url' => 'https://s.com/p', 'status' => 'done', 'assets' => [] ],
        ];

        $ajax = new ScannerAjax();
        $this->assertFalse( $ajax->__test_should_persist_r_orig( $pages_raw ), 'ratchet disabled → should_persist_r_orig false' );
    }

    /**
     * B3 — ratchet_enabled=false: no merge fires even on ET rescan.
     * is_et_rescan + r_orig_matches helpers exercised via seams; confirm
     * that r_orig_matches returns false when transient is false.
     */
    public function test_b3_gated_off_ratchet_disabled_no_merge(): void {
        // Verify is_et_rescan detects extra_time_charged.
        $pages_et = [
            [ 'url' => 'https://s.com/p', 'status' => 'done', 'assets' => [], 'extra_time_charged' => true ],
        ];
        $pages_normal = [
            [ 'url' => 'https://s.com/p', 'status' => 'done', 'assets' => [] ],
        ];
        $ajax = new ScannerAjax();
        $this->assertTrue(  $ajax->__test_is_et_rescan( $pages_et ),     'extra_time_charged=true → ET rescan' );
        $this->assertFalse( $ajax->__test_is_et_rescan( $pages_normal ), 'no extra_time_charged → not ET rescan' );

        // When get_transient returns false, r_orig_matches must return false.
        $this->assertFalse( $ajax->__test_r_orig_matches( false, $pages_et ), 'false transient → r_orig_matches false' );
    }

    /**
     * AC-ETR-9 staleness: r_orig_matches returns false when transient URL set
     * does not contain all scanned URLs.
     */
    public function test_b3_ac_etr9_staleness_guard_different_url_set(): void {
        $ajax = new ScannerAjax();

        // Transient covers only page A; rescan covers A + B → mismatch.
        $r_orig = [
            'urls'  => [ 'https://s.com/a' ],
            'rules' => [ [ 'url_pattern' => 'https://s.com/a', 'asset_handle' => 'h', 'asset_type' => 'css', 'device_type' => 'all', 'group_id' => 2 ] ],
        ];
        $pages_raw = [
            [ 'url' => 'https://s.com/a', 'status' => 'done', 'assets' => [] ],
            [ 'url' => 'https://s.com/b', 'status' => 'done', 'assets' => [] ],
        ];
        $this->assertFalse( $ajax->__test_r_orig_matches( $r_orig, $pages_raw ), 'URL set mismatch → r_orig_matches false' );

        // Transient covers same URL set → matches.
        $r_orig_match = [
            'urls'  => [ 'https://s.com/a', 'https://s.com/b' ],
            'rules' => $r_orig['rules'],
        ];
        $this->assertTrue( $ajax->__test_r_orig_matches( $r_orig_match, $pages_raw ), 'Same URL set → r_orig_matches true' );
    }

    /**
     * B3 restore: ET rescan + ratchet_enabled + fresh matching R_orig containing
     * a rule the rescan benignly dropped → merged rules include the restored rule
     * and safe_count reflects it.
     *
     * Tests recompute_by_page + merge-gate directly via seams.
     */
    public function test_b3_restore_et_rescan_merges_and_recomputes_by_page(): void {
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( string $url, ?int $component = null ) {
            $parts = parse_url( $url );
            if ( null === $component ) { return $parts; }
            $map = [ PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PATH => 'path' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );

        $pat = 'https://s.com/p';

        // R_orig has one safe desktop rule that the ET rescan drops (benign demotion).
        $r_orig = [
            'urls'  => [ $pat ],
            'rules' => [
                [
                    'url_pattern'  => $pat,
                    'asset_handle' => 'orig-h',
                    'asset_type'   => 'css',
                    'device_type'  => 'desktop',
                    'group_id'     => 1,
                    'match_type'   => 'exact',
                    'source_label' => 'AA Scanner',
                ],
            ],
        ];

        // ET rescan: asset loaded with zero coverage → aggressive rule emitted by builder.
        // orig-h is loaded but covered (needed) with benign demote_class → benign drop.
        $rescan_pages = [
            [
                'url'               => $pat,
                'status'            => 'done',
                'extra_time_charged' => true,
                'assets'            => [
                    [
                        'handle'  => 'new-h',
                        'type'    => 'style',
                        'desktop' => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => 'aggressive' ],
                        'mobile'  => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => 'aggressive' ],
                    ],
                    [
                        'handle'       => 'orig-h',
                        'type'         => 'style',
                        'demote_class' => 'benign',
                        'desktop'      => [ 'loaded' => true, 'coverage' => 0.001, 'bucket' => 'needed' ],
                        'mobile'       => [ 'loaded' => true, 'coverage' => 0.001, 'bucket' => 'needed' ],
                    ],
                ],
            ],
        ];

        // Build R_et via RatchetMerger::merge (the same path the production code takes).
        $merger       = new \CUScanner\Scanner\RatchetMerger();
        $merged_rules = $merger->merge( $r_orig['rules'], $rescan_pages );

        // Confirm orig-h (benign) is restored in merged rules.
        $has_orig = false;
        foreach ( $merged_rules as $r ) {
            if ( 'orig-h' === $r['asset_handle'] && 1 === $r['group_id'] ) {
                $has_orig = true;
                break;
            }
        }
        $this->assertTrue( $has_orig, 'Benign-dropped orig rule must be restored by merge' );

        // recompute_by_page invariant: safe count matches group_id=1 total.
        $orig_by_page = [ 0 => [ 'safe' => 0, 'aggressive' => 0, 'needed' => 2 ] ];
        $ajax         = new ScannerAjax();
        $by_page      = $ajax->__test_recompute_by_page( $merged_rules, $rescan_pages, $orig_by_page );

        $safe_from_rules = count( array_filter( $merged_rules, fn( $r ) => 1 === $r['group_id'] ) );
        $agg_from_rules  = count( array_filter( $merged_rules, fn( $r ) => 2 === $r['group_id'] ) );
        $safe_from_by_page = array_sum( array_column( $by_page, 'safe' ) );
        $agg_from_by_page  = array_sum( array_column( $by_page, 'aggressive' ) );

        $this->assertSame( $safe_from_rules, $safe_from_by_page, 'by_page safe sum must equal group_id=1 rule count' );
        $this->assertSame( $agg_from_rules,  $agg_from_by_page,  'by_page aggressive sum must equal group_id=2 rule count' );
        // needed preserved from orig_by_page.
        $this->assertSame( 2, $by_page[0]['needed'], 'needed count must be preserved from original by_page' );
    }

    /**
     * by_page invariant sanity: recompute_by_page with zero merged rules
     * yields all-zero safe/aggressive per page.
     */
    public function test_b3_by_page_invariant_zero_rules(): void {
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( string $url, ?int $component = null ) {
            $parts = parse_url( $url );
            if ( null === $component ) { return $parts; }
            $map = [ PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PATH => 'path' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );

        $pages_raw    = [
            [ 'url' => 'https://s.com/p', 'status' => 'done', 'assets' => [] ],
        ];
        $orig_by_page = [ 0 => [ 'safe' => 1, 'aggressive' => 1, 'needed' => 3 ] ];
        $by_page      = ( new ScannerAjax() )->__test_recompute_by_page( [], $pages_raw, $orig_by_page );

        $this->assertSame( 0, $by_page[0]['safe'],       'zero rules → safe=0' );
        $this->assertSame( 0, $by_page[0]['aggressive'], 'zero rules → aggressive=0' );
        $this->assertSame( 3, $by_page[0]['needed'],     'needed preserved from orig' );
    }

    // ─────────────────────────────────────────────────────────────────────
    // B4 — ratchet_recovered in pages_payload
    // ─────────────────────────────────────────────────────────────────────

    /**
     * B4 — ratchet ran + benign restore: pages_payload row for the matching
     * page must have ratchet_recovered >= 1.
     *
     * Exercises the __test_inject_ratchet_recovered seam to isolate B4 logic
     * from Railway/do_build_result I/O.
     */
    public function test_b4_ratchet_recovered_stamped_when_ratchet_ran(): void {
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( function ( string $url, ?int $component = null ) {
            $parts = parse_url( $url );
            if ( null === $component ) { return $parts; }
            $map = [ PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PATH => 'path' ];
            return $parts[ $map[ $component ] ?? '' ] ?? null;
        } );

        $pat = 'https://s.com/p';

        // R_orig: one benign-demoted rule.
        $r_orig_rules = [
            [
                'url_pattern'  => $pat,
                'asset_handle' => 'orig-h',
                'asset_type'   => 'css',
                'device_type'  => 'desktop',
                'group_id'     => 1,
                'match_type'   => 'exact',
                'source_label' => 'AA Scanner',
            ],
        ];
        $rescan_pages = [
            [
                'url'                => $pat,
                'status'             => 'done',
                'extra_time_charged' => true,
                'assets'             => [
                    [
                        'handle'       => 'orig-h',
                        'type'         => 'style',
                        'demote_class' => 'benign',
                        'desktop'      => [ 'loaded' => true, 'coverage' => 0.001, 'bucket' => 'needed' ],
                        'mobile'       => [ 'loaded' => true, 'coverage' => 0.001, 'bucket' => 'needed' ],
                    ],
                ],
            ],
        ];

        // Run merge — this populates recovered_by_pattern.
        $merger = new \CUScanner\Scanner\RatchetMerger();
        $merger->merge( $r_orig_rules, $rescan_pages );

        // Simulate pages_payload as built by AIAS_Scan_Status::build_pages.
        $pages_payload = [
            [
                'n'            => 1,
                'url'          => $pat,
                'status_class' => 'ok',
                'status_label' => 'Done',
                'credits'      => 1,
                'safe'         => 1,
                'aggressive'   => 0,
                'needed'       => 0,
                'et_candidate' => false,
            ],
        ];

        // Stamp ratchet_recovered via the seam.
        $ajax   = new ScannerAjax();
        $result = $ajax->__test_inject_ratchet_recovered( $pages_payload, $rescan_pages, $merger );

        $this->assertArrayHasKey( 'ratchet_recovered', $result[0], 'ratchet_recovered key must be present' );
        $this->assertGreaterThanOrEqual( 1, $result[0]['ratchet_recovered'], 'restored rule → ratchet_recovered >= 1' );
    }

    /**
     * B4 — ratchet did NOT run (merger null): ratchet_recovered is absent / 0
     * on all page rows.
     *
     * When $merger === null the production code skips the stamp loop entirely,
     * so pages rows simply lack the key (treated as 0 by JS).
     */
    public function test_b4_ratchet_recovered_absent_when_ratchet_did_not_run(): void {
        $pages_payload = [
            [
                'n'            => 1,
                'url'          => 'https://s.com/p',
                'status_class' => 'ok',
                'status_label' => 'Done',
                'credits'      => 1,
                'safe'         => 2,
                'aggressive'   => 1,
                'needed'       => 0,
                'et_candidate' => false,
            ],
        ];

        // $merger = null → stamp loop does not run → key absent.
        $ajax   = new ScannerAjax();
        $result = $ajax->__test_inject_ratchet_recovered(
            $pages_payload,
            [ [ 'url' => 'https://s.com/p', 'status' => 'done', 'assets' => [] ] ],
            null
        );

        $recovered = $result[0]['ratchet_recovered'] ?? 0;
        $this->assertSame( 0, $recovered, 'no ratchet → ratchet_recovered must be 0/absent' );
    }

    /**
     * AC-3 — ratchet_skip_reason returns the exact diagnostic reason for each path.
     */
    public function test_ac3_ratchet_skip_reason_selection(): void {
        $ajax = new ScannerAjax();

        // enabled + ET + r_orig absent/empty + no match → r_orig_absent_or_empty
        $this->assertSame( 'r_orig_absent_or_empty',
            $ajax->__test_ratchet_skip_reason( true, true, null, false ) );
        $this->assertSame( 'r_orig_absent_or_empty',
            $ajax->__test_ratchet_skip_reason( true, true, [ 'rules' => [] ], false ) );

        // enabled + ET + r_orig present + no match → url_set_mismatch
        $this->assertSame( 'url_set_mismatch',
            $ajax->__test_ratchet_skip_reason( true, true, [ 'rules' => [ [ 'x' => 1 ] ], 'urls' => [ 'u' ] ], false ) );

        // ET + ratchet disabled → ratchet_disabled
        $this->assertSame( 'ratchet_disabled',
            $ajax->__test_ratchet_skip_reason( false, true, null, false ) );

        // enabled + ET + matches → null (merge runs, no skip)
        $this->assertNull(
            $ajax->__test_ratchet_skip_reason( true, true, [ 'rules' => [ [ 'x' => 1 ] ] ], true ) );

        // not an ET rescan → null (N/A)
        $this->assertNull(
            $ajax->__test_ratchet_skip_reason( true, false, null, false ) );
    }

    /**
     * AC-3b — log gate is a no-op when WP_DEBUG_LOG is not enabled (test env).
     */
    public function test_ac3b_ratchet_debug_gate_off_by_default(): void {
        $ajax = new ScannerAjax();
        $this->assertFalse( $ajax->__test_ratchet_debug_enabled() );
    }
}

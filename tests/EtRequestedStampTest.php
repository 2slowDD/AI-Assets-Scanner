<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * et_requested — the Step-4 "Needs Extra Time" note stays off on a page that just had Extra
 * Time (operator 2026-09-14). A new serialized wire field, so per P17 both halves run through
 * the REAL production paths, never an injected row shape:
 *   (a) producer: perform_submit_side_effects() persists cu_scanner_et_urls_<job_id> from the
 *       pages actually sent (extra_time truthy only), TTL 7200; nothing when no page asked.
 *   (b) consumer: do_build_result() stamps every page row's et_requested from that transient,
 *       on BOTH the live return and the persisted aias_last_result option.
 *   (a+b) round trip: the producer's write is what the consumer reads — no hand-seeded key.
 * Only the Railway HTTP boundary and WP storage are stubbed (array-backed).
 */
class EtRequestedStampTest extends TestCase {

    private const URL_A = 'https://site.test/a/?nowprocket';
    private const URL_B = 'https://site.test/b/';
    private const URL_C = 'https://site.test/c/';

    /** @var array<string,mixed> */
    private array $options = [];
    /** @var array<string,mixed> */
    private array $transients = [];
    /** @var array<string,int> */
    private array $ttls = [];

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->options    = [];
        $this->transients = [];
        $this->ttls       = [];
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /** A worker page row that classifies to one SAFE rule (legacy absent/absent derivation). */
    private function worker_page( string $url ): array {
        return [
            'url'    => $url,
            'status' => 'done',
            'assets' => [ [
                'handle'  => 'h-' . md5( $url ),
                'type'    => 'style',
                'desktop' => [ 'loaded' => false, 'coverage' => 0.0 ],
                'mobile'  => [ 'loaded' => false, 'coverage' => 0.0 ],
            ] ],
        ];
    }

    /** A reshaped payload page exactly as reshape_page_specs() hands it to Railway. */
    private function sent_page( string $url, bool $extra_time, array $suffixes = [] ): array {
        return [
            'url'             => $url,
            'bypass_token'    => 'bt',
            'bypass_suffixes' => $suffixes,
            'extra_time'      => $extra_time,
            'submitted_url'   => $url,
        ];
    }

    /**
     * One array-backed stub set serving BOTH the submit side effects and do_build_result(),
     * so the round-trip test shares a single transient store between producer and consumer.
     * The do_build_result() half mirrors SyncScopeBuildResultTest's stub_everything_impl().
     */
    private function stub_wp( array $worker_pages ): void {
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( (string) $u, $c ) );
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://site.test' );
        WP_Mock::userFunction( '__' )->andReturnUsing( fn( $t, $d = null ) => $t );
        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [
            'status' => 'complete', 'total' => count( $worker_pages ), 'completed' => count( $worker_pages ), 'pages' => $worker_pages, 'flags' => [],
        ] ) );
        WP_Mock::userFunction( 'get_option' )->andReturnUsing( function ( $k, $default = false ) {
            if ( 'cu_scanner_railway_url' === $k ) { return 'https://cu-scanner-railway-production.up.railway.app'; }
            if ( 'cu_scanner_api_key' === $k )     { return 'api-key-123'; }
            return array_key_exists( $k, $this->options ) ? $this->options[ $k ] : $default;
        } );
        WP_Mock::userFunction( 'update_option' )->andReturnUsing( function ( $k, $v ) { $this->options[ $k ] = $v; return true; } );
        WP_Mock::userFunction( 'delete_option' )->andReturn( true );
        WP_Mock::userFunction( 'get_transient' )->andReturnUsing( fn( $k ) => $this->transients[ $k ] ?? false );
        WP_Mock::userFunction( 'set_transient' )->andReturnUsing( function ( $k, $v, $ttl = 0 ) {
            $this->transients[ $k ] = $v;
            $this->ttls[ $k ]       = $ttl;
            return true;
        } );
        WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
        WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( true );
        WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturn( true );
        WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $d, $f = 0 ) => json_encode( $d, $f ) );
        WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value = null ) => $value );
        WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
        WP_Mock::userFunction( 'do_action' )->andReturn( null );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => (string) $v );
        WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
        WP_Mock::userFunction( 'absint' )->andReturnUsing( fn( $v ) => abs( (int) $v ) );
    }

    private function submit( string $job_id, array $pages_sent, array $et_urls ): void {
        ( new ScannerAjax() )->perform_submit_side_effects(
            [ 'job_id' => $job_id ],
            [ 'urls' => array_column( $pages_sent, 'url' ), 'extra_time_urls' => $et_urls ],
            [],
            'bt',
            'https://cu-scanner-railway-production.up.railway.app',
            'tok',
            1,
            $pages_sent
        );
    }

    /** url => et_requested for one writer's pages[], asserting the key is present on every row. */
    private function et_by_url( array $pages, string $where ): array {
        $out = [];
        foreach ( $pages as $row ) {
            $this->assertArrayHasKey( 'et_requested', $row, "$where: every page row carries et_requested" );
            $out[ $row['url'] ] = $row['et_requested'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ (a) producer
    public function test_submit_persists_only_the_extra_time_urls_with_the_job_ttl(): void {
        $this->stub_wp( [] );
        $this->submit( 'job-sub', [ $this->sent_page( self::URL_A, true, [ 'nowprocket' ] ), $this->sent_page( self::URL_B, false ) ], [ self::URL_A ] );

        $this->assertArrayHasKey( 'cu_scanner_et_urls_job-sub', $this->transients, 'the ET URL set is persisted under the job id' );
        $this->assertSame( [ self::URL_A => true ], $this->transients['cu_scanner_et_urls_job-sub'], 'only the page sent WITH Extra Time, keyed by its final scan URL' );
        $this->assertSame( 7200, $this->ttls['cu_scanner_et_urls_job-sub'], 'TTL matches the job + bypass-map transients' );
    }

    public function test_submit_with_no_extra_time_page_stores_no_et_set(): void {
        $this->stub_wp( [] );
        $this->submit( 'job-plain', [ $this->sent_page( self::URL_A, false, [ 'nowprocket' ] ), $this->sent_page( self::URL_B, false ) ], [] );

        // Non-vacuity: the side-effect runner reached the transient block (bypass map written).
        $this->assertArrayHasKey( 'cu_scanner_bypass_map_job-plain', $this->transients, 'fixture sanity: the submit side effects ran to the transient block' );
        $this->assertArrayNotHasKey( 'cu_scanner_et_urls_job-plain', $this->transients, 'fail-closed: no ET page => no ET URL set stored' );
    }

    // ------------------------------------------------------------------ (b) consumer
    public function test_build_result_stamps_et_requested_on_both_writers(): void {
        // The ET page sits in the MIDDLE so a row/raw index misalignment cannot pass.
        $this->transients['cu_scanner_et_urls_job-b1'] = [ self::URL_A => true ];
        $this->stub_wp( [ $this->worker_page( self::URL_B ), $this->worker_page( self::URL_A ), $this->worker_page( self::URL_C ) ] );

        $payload = ( new ScannerAjax() )->do_build_result( 'job-b1', 'tok' );

        $expected = [ self::URL_B => false, self::URL_A => true, self::URL_C => false ];
        $this->assertSame( $expected, $this->et_by_url( $payload['pages'], 'live payload' ) );
        $this->assertArrayHasKey( 'aias_last_result', $this->options, 'do_build_result persisted the restore payload' );
        $this->assertSame( $expected, $this->et_by_url( $this->options['aias_last_result']['pages'], 'aias_last_result option' ) );
    }

    public function test_build_result_without_the_transient_stamps_every_row_false(): void {
        $this->stub_wp( [ $this->worker_page( self::URL_A ), $this->worker_page( self::URL_B ) ] );

        $payload = ( new ScannerAjax() )->do_build_result( 'job-b2', 'tok' );

        $expected = [ self::URL_A => false, self::URL_B => false ];
        $this->assertSame( $expected, $this->et_by_url( $payload['pages'], 'live payload' ) );
        $this->assertSame( $expected, $this->et_by_url( $this->options['aias_last_result']['pages'], 'aias_last_result option' ) );
    }

    // ------------------------------------------------------------------ (a+b) round trip
    public function test_submit_to_build_round_trip_through_the_real_transient(): void {
        $this->stub_wp( [ $this->worker_page( self::URL_B ), $this->worker_page( self::URL_A ) ] );
        $this->submit( 'job-rt', [ $this->sent_page( self::URL_B, false ), $this->sent_page( self::URL_A, true, [ 'nowprocket' ] ) ], [ self::URL_A ] );

        $payload = ( new ScannerAjax() )->do_build_result( 'job-rt', 'tok' );

        $expected = [ self::URL_B => false, self::URL_A => true ];
        $this->assertSame( $expected, $this->et_by_url( $payload['pages'], 'live payload' ) );
        $this->assertSame( $expected, $this->et_by_url( $this->options['aias_last_result']['pages'], 'aias_last_result option' ) );
    }
}

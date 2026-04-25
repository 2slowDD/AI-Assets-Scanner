<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\EventEmitter;
use CUScanner\Scanner\PluginDetector;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Integration-light tests: verifies that the scan-trigger logic emits the correct
 * events and page payloads carry bypass_suffixes.
 *
 * Strategy: call EventEmitter::emit() directly (mirroring what submit_job does) and
 * capture the queued events from the update_option call, since emit() stores into
 * the WP option queue synchronously. The flush/client path is irrelevant for these
 * correctness tests.
 */
class ScanTriggerEventsTest extends TestCase {
    /** @var array<array> Queued events captured from update_option calls */
    private array $queued = [];

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->queued = [];

        // EventEmitter::emit() reads the current queue via get_option then writes
        // it back via update_option. We capture the written queue.
        $self = $this;
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_pending_events', [] )
            ->andReturnUsing( fn() => $self->queued );
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( $self ) {
                if ( $key === 'aias_pending_events' ) {
                    $self->queued = $value;
                }
                return true;
            } );
        WP_Mock::userFunction( 'wp_next_scheduled' )
            ->with( 'aias_event_emitter_flush' )
            ->andReturn( false );
        WP_Mock::userFunction( 'wp_schedule_single_event' )
            ->andReturn( true );
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Simulate the submit_job event sequence for given typed entries + job_id. */
    private function runEmitSequence( array $typed, string $job_id, string $primary_url = 'https://example.com/' ): void {
        $scan_id = substr( hash( 'sha256', $job_id ), 0, 16 );

        EventEmitter::emit(
            'scan_request_received',
            'operational',
            [
                'scan_id'           => $scan_id,
                'path_hash'         => substr( hash( 'sha256', $primary_url ), 0, 16 ),
                'optimizers_active' => count( $typed ),
            ],
            $scan_id
        );

        foreach ( $typed as $file => $entry ) {
            EventEmitter::emit(
                'optimizer_detected',
                'operational',
                [
                    'plugin'       => PluginDetector::plugin_file_to_enum( $file ),
                    'class'        => $entry['class'] ?? '',
                    'bypass_query' => substr( hash( 'sha256', (string) ( $entry['bypass_query'] ?? '' ) ), 0, 16 ),
                    'scan_id'      => $scan_id,
                ],
                $scan_id
            );
        }
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_scan_request_received_emitted_once_per_scan(): void {
        $typed = [
            'wp-rocket/wp-rocket.php' => [
                'name' => 'WP Rocket', 'class' => 'A', 'bypass_query' => 'nowprocket',
                'disable_method' => null, 'warning' => null,
            ],
            'perfmatters/perfmatters.php' => [
                'name' => 'Perfmatters', 'class' => 'A', 'bypass_query' => 'perfmattersoff',
                'disable_method' => null, 'warning' => null,
            ],
        ];

        $this->runEmitSequence( $typed, 'railway-job-42' );

        $received = array_filter( $this->queued, fn( $e ) => $e['name'] === 'scan_request_received' );
        $detected = array_filter( $this->queued, fn( $e ) => $e['name'] === 'optimizer_detected' );

        $this->assertCount( 1, $received, 'Expected exactly 1 scan_request_received event' );
        $this->assertCount( 2, $detected, 'Expected exactly 2 optimizer_detected events' );
    }

    public function test_optimizer_detected_events_have_correct_fields(): void {
        $typed = [
            'wp-rocket/wp-rocket.php' => [
                'name' => 'WP Rocket', 'class' => 'A', 'bypass_query' => 'nowprocket',
                'disable_method' => null, 'warning' => null,
            ],
            'perfmatters/perfmatters.php' => [
                'name' => 'Perfmatters', 'class' => 'A', 'bypass_query' => 'perfmattersoff',
                'disable_method' => null, 'warning' => null,
            ],
        ];

        $this->runEmitSequence( $typed, 'railway-job-42' );

        $detected = array_values( array_filter(
            $this->queued,
            fn( $e ) => $e['name'] === 'optimizer_detected'
        ) );

        $this->assertCount( 2, $detected );

        // First: WP Rocket
        $this->assertSame( 'rocket', $detected[0]['fields']['plugin'] );
        $this->assertSame( 'A',      $detected[0]['fields']['class'] );
        $this->assertSame( 16,       strlen( $detected[0]['fields']['bypass_query'] ),
            'bypass_query field should be 16-char hex hash' );

        // Second: Perfmatters
        $this->assertSame( 'perfmatters', $detected[1]['fields']['plugin'] );
        $this->assertSame( 'A',           $detected[1]['fields']['class'] );
    }

    public function test_bypass_suffixes_in_page_payload_for_rocket_and_perfmatters(): void {
        // Validates the page payload shape produced by build_bypass_suffixes
        $typed = [
            'wp-rocket/wp-rocket.php' => [
                'name' => 'WP Rocket', 'class' => 'A', 'bypass_query' => 'nowprocket',
                'disable_method' => null, 'warning' => null,
            ],
            'perfmatters/perfmatters.php' => [
                'name' => 'Perfmatters', 'class' => 'A', 'bypass_query' => 'perfmattersoff',
                'disable_method' => null, 'warning' => null,
            ],
        ];

        $bypass_suffixes = PluginDetector::build_bypass_suffixes( $typed );

        $urls  = [ 'https://example.com/page1', 'https://example.com/page2' ];
        $pages = array_map(
            fn( $u ) => [
                'url'             => $u,
                'bypass_token'    => 'token-abc',
                'bypass_suffixes' => $bypass_suffixes,
            ],
            $urls
        );

        foreach ( $pages as $page ) {
            $this->assertArrayHasKey( 'bypass_suffixes', $page );
            $this->assertContains( 'nowprocket',     $page['bypass_suffixes'] );
            $this->assertContains( 'perfmattersoff', $page['bypass_suffixes'] );
        }
    }

    public function test_scan_request_received_fields_structure(): void {
        $this->runEmitSequence( [], 'some-job-id', 'https://example.com/' );

        $received = array_values( array_filter(
            $this->queued,
            fn( $e ) => $e['name'] === 'scan_request_received'
        ) );

        $this->assertCount( 1, $received );
        $this->assertSame( 'operational', $received[0]['category'] );
        $this->assertArrayHasKey( 'scan_id',           $received[0]['fields'] );
        $this->assertArrayHasKey( 'path_hash',         $received[0]['fields'] );
        $this->assertArrayHasKey( 'optimizers_active', $received[0]['fields'] );
        $this->assertSame( 16, strlen( $received[0]['fields']['scan_id'] ) );
        $this->assertSame( 16, strlen( $received[0]['fields']['path_hash'] ) );
        $this->assertSame( 0,  $received[0]['fields']['optimizers_active'] );
    }

    public function test_no_optimizer_detected_events_when_no_plugins_active(): void {
        $this->runEmitSequence( [], 'job-empty' );

        $detected = array_filter( $this->queued, fn( $e ) => $e['name'] === 'optimizer_detected' );
        $received = array_filter( $this->queued, fn( $e ) => $e['name'] === 'scan_request_received' );

        $this->assertCount( 0, $detected );
        $this->assertCount( 1, $received );
    }
}

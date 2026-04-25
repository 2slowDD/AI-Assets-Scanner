<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Local FIFO queue + debounced flush for cu_scanner_events records.
 *
 * Stores events in `aias_pending_events` option (autoload=false). Flush is
 * scheduled via wp_schedule_single_event; on HTTP failure, the failed batch
 * is re-queued and flush is rescheduled with a 5-minute backoff.
 *
 * Cap is 1000 events. On overflow, the oldest 100 are dropped and a single
 * `event_queue_overflow` security event is added — rate-limited to one per
 * 60 seconds via the `aias_event_overflow_warned` transient.
 */
class EventEmitter {
    private const QUEUE_OPTION   = 'aias_pending_events';
    private const FLUSH_HOOK     = 'aias_event_emitter_flush';
    private const MAX_QUEUE      = 1000;
    private const DROP_BATCH     = 100;
    private const OVERFLOW_TRANS = 'aias_event_overflow_warned';
    private const FLUSH_DEBOUNCE = 1;
    private const BACKOFF_SECS   = 300;
    private const BATCH_SIZE     = 100;

    private static ?object $client_for_testing = null;

    public static function set_client_for_testing( ?object $client ): void {
        self::$client_for_testing = $client;
    }

    public static function emit( string $name, string $category, array $fields, string $scan_id ): void {
        $queue = get_option( self::QUEUE_OPTION, [] );
        if ( ! is_array( $queue ) ) {
            $queue = [];
        }

        if ( count( $queue ) >= self::MAX_QUEUE ) {
            $oldest_at = isset( $queue[0]['at'] ) ? (int) $queue[0]['at'] : time();
            // Drop oldest batch
            $queue = array_slice( $queue, self::DROP_BATCH );
            // Rate-limited overflow event
            $last_warned = get_transient( self::OVERFLOW_TRANS );
            if ( ! $last_warned ) {
                $queue[] = [
                    'name'     => 'event_queue_overflow',
                    'category' => 'security',
                    'fields'   => [
                        'dropped_count' => self::DROP_BATCH,
                        'oldest_at'     => $oldest_at,
                    ],
                    'scan_id'  => $scan_id,
                    'at'       => time(),
                ];
                set_transient( self::OVERFLOW_TRANS, time(), 60 );
            }
        }

        $queue[] = [
            'name'     => $name,
            'category' => $category,
            'fields'   => $fields,
            'scan_id'  => $scan_id,
            'at'       => time(),
        ];
        update_option( self::QUEUE_OPTION, $queue, false );

        if ( ! wp_next_scheduled( self::FLUSH_HOOK ) ) {
            wp_schedule_single_event( time() + self::FLUSH_DEBOUNCE, self::FLUSH_HOOK );
        }
    }

    public static function flush(): int {
        $queue = get_option( self::QUEUE_OPTION, [] );
        if ( ! is_array( $queue ) || empty( $queue ) ) {
            return 0;
        }

        $client = self::resolve_client();
        if ( ! $client ) {
            wp_schedule_single_event( time() + self::BACKOFF_SECS, self::FLUSH_HOOK );
            return 0;
        }

        $by_scan = [];
        foreach ( $queue as $row ) {
            $sid = (string) ( $row['scan_id'] ?? '' );
            $by_scan[ $sid ][] = $row;
        }

        $sent      = 0;
        $remaining = [];
        foreach ( $by_scan as $sid => $rows ) {
            foreach ( array_chunk( $rows, self::BATCH_SIZE ) as $batch ) {
                $payload = array_map(
                    static fn( $r ) => [
                        'name'     => $r['name'],
                        'category' => $r['category'],
                        'fields'   => $r['fields'],
                    ],
                    $batch
                );
                $resp = $client->emit_events( $sid, $payload );
                if ( ! is_array( $resp ) || ! isset( $resp['accepted'] ) ) {
                    // Failure — keep batch for retry
                    $remaining = array_merge( $remaining, $batch );
                    continue;
                }
                $sent += (int) $resp['accepted'];
            }
        }

        update_option( self::QUEUE_OPTION, $remaining, false );
        if ( ! empty( $remaining ) ) {
            wp_schedule_single_event( time() + self::BACKOFF_SECS, self::FLUSH_HOOK );
        }
        return $sent;
    }

    private static function resolve_client(): ?object {
        if ( self::$client_for_testing ) {
            return self::$client_for_testing;
        }
        // Try the project's existing WpserviceClient construction path.
        if ( class_exists( '\\CUScanner\\Settings' ) && class_exists( '\\CUScanner\\Api\\WpserviceClient' ) ) {
            $settings = new \CUScanner\Settings();
            $api_key  = $settings->get_api_key();
            if ( ! empty( $api_key ) ) {
                $base_url = defined( 'CU_SCANNER_WPSERVICE_BASE' ) ? CU_SCANNER_WPSERVICE_BASE : ( defined( 'CU_SCANNER_WPSERVICE_URL' ) ? CU_SCANNER_WPSERVICE_URL : '' );
                return new \CUScanner\Api\WpserviceClient( $base_url, $api_key );
            }
        }
        return null;
    }
}

if ( function_exists( 'add_action' ) ) {
    add_action( 'aias_event_emitter_flush', [ \CUScanner\Scanner\EventEmitter::class, 'flush' ] );
}

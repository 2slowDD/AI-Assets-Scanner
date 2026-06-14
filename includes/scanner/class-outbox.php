<?php
namespace CUScanner\Scanner;

use CUScanner\Api\HttpException;

defined( 'ABSPATH' ) || exit;

class Outbox {
    public const OPTION_KEY   = 'cu_scanner_outbox';
    public const LOCK_KEY     = 'cu_scanner_outbox_lock';
    public const CRON_HOOK    = 'cu_scanner_outbox_replay';

    public const BASE_BACKOFF = 30;
    public const MAX_BACKOFF  = 3600;
    public const HORIZON      = 86400;
    public const MAX_ATTEMPTS = 50;
    public const LOCK_TTL     = 120;

    /** Retryable iff the failure is an HttpException with a network(0) or 5xx status. */
    public static function is_retryable( \Throwable $e ): bool {
        if ( ! $e instanceof HttpException ) {
            return false;
        }
        $code = $e->get_status_code();
        return $code === 0 || ( $code >= 500 && $code <= 599 ); // 5xx incl. the 503 soft-cap (queue_full)
    }

    /**
     * Enqueue a scan intent into the outbox.
     *
     * Rejects if an entry with status==='pending' already exists (1-entry/site cap, AC-O-5).
     * Deletes the per-user reserve pending-token transient so the outbox becomes the
     * sole owner of the reservation half-state (AC-O-10).
     *
     * @param array $intent Scan intent (must include 'user_id').
     * @return bool True on success, false if a pending entry already exists.
     */
    public static function enqueue( array $intent ): bool {
        $existing = self::load();
        if ( $existing && ( $existing['status'] ?? '' ) === 'pending' ) {
            return false; // 1-entry/site cap (AC-O-5)
        }
        $now   = time();
        $entry = [
            'intent'          => $intent,
            'status'          => 'pending',
            'attempts'        => 0,
            'created_at'      => $now,
            'next_attempt_at' => $now,
            'job_token'       => null,
            'last_error'      => '',
        ];
        self::save( $entry );
        // AC-O-10: outbox owns the half-state; drop any lingering reserve pending-token.
        $user_id = (int) ( $intent['user_id'] ?? 0 );
        delete_transient( 'cu_scanner_pending_token_' . $user_id );
        self::schedule( $now );
        return true;
    }

    // -------------------------------------------------------------------------
    // Private helpers — load / save / schedule
    // dispatch(), lock helpers, outbox_state_for_user(), replay() are Tasks 6-8.
    // -------------------------------------------------------------------------

    private static function load(): ?array {
        $v = get_option( self::OPTION_KEY );
        return is_array( $v ) ? $v : null;
    }

    private static function save( array $entry ): void {
        update_option( self::OPTION_KEY, $entry, false ); // autoload = no
    }

    private static function schedule( int $at ): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( $at, self::CRON_HOOK );
        }
    }
}

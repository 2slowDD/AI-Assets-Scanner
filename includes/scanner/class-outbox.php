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
        $user_id = (int) ( $intent['user_id'] ?? 0 );
        // FU-OUTBOX-ADOPT-RESERVE: adopt the account's outstanding reserve token (if any) as the
        // initial half-state so the outbox owns the reference and releases it on the first dispatch
        // pass. Discarding it (old AC-O-10) orphaned a still-'reserved' token when the original
        // release was skipped/raced during an outage -> outbox 409'd against its own reservation.
        $pending = get_transient( 'cu_scanner_pending_token_' . $user_id );
        $now     = time();
        $entry   = [
            'intent'          => $intent,
            'status'          => 'pending',
            'attempts'        => 0,
            'created_at'      => $now,
            'next_attempt_at' => $now,
            'job_token'       => ( is_string( $pending ) && '' !== $pending ) ? $pending : null,
            'last_error'      => '',
        ];
        self::save( $entry );
        // Still delete the transient so handle_failure() can't also act on the same token.
        delete_transient( 'cu_scanner_pending_token_' . $user_id );
        self::schedule( $now );
        return true;
    }

    // -------------------------------------------------------------------------
    // Concurrency: claim-lock (Tasks 7).
    // -------------------------------------------------------------------------

    /**
     * Attempt to claim the outbox dispatch lock.
     *
     * Uses add_option NX semantics: succeeds only when the key is absent.
     * If the option already exists but the stored timestamp is older than
     * LOCK_TTL seconds (stale lock), the lock is taken over via update_option.
     *
     * @return bool True when the lock was acquired, false if held by another caller.
     */
    public static function acquire_lock(): bool {
        if ( add_option( self::LOCK_KEY, time(), '', 'no' ) ) {
            return true;
        }
        $held = (int) get_option( self::LOCK_KEY );
        if ( ( time() - $held ) > self::LOCK_TTL ) { // stale → take over
            update_option( self::LOCK_KEY, time(), false );
            return true;
        }
        return false;
    }

    /** Release the outbox dispatch lock. */
    public static function release_lock(): void {
        delete_option( self::LOCK_KEY );
    }

    // -------------------------------------------------------------------------
    // Backoff helpers (Tasks 7).
    // -------------------------------------------------------------------------

    /**
     * Deterministic backoff core (jitter is added in backoff()).
     *
     * Returns BASE_BACKOFF * 2^attempts, capped at MAX_BACKOFF.
     *
     * @param int $attempts Number of prior attempts (0-based).
     * @return int Delay in seconds.
     */
    public static function next_delay( int $attempts ): int {
        return (int) min( self::MAX_BACKOFF, self::BASE_BACKOFF * ( 2 ** $attempts ) );
    }

    /**
     * Record a failed attempt: bump counter, compute next-attempt timestamp
     * (with jitter), persist, and re-schedule.
     *
     * @param array      $entry The current outbox entry.
     * @param \Throwable $e     The caught exception.
     * @return string Always 'pending'.
     */
    private static function backoff( array $entry, \Throwable $e ): string {
        $entry['attempts']++;
        $jitter = random_int( 0, self::BASE_BACKOFF );
        $entry['next_attempt_at'] = time() + self::next_delay( $entry['attempts'] ) + $jitter;
        $entry['last_error']      = self::short( $e->getMessage() );
        $entry['status']          = 'pending';
        self::save( $entry );
        self::schedule( $entry['next_attempt_at'] );
        return 'pending';
    }

    /** Truncate a string to 200 characters (mb-safe). */
    private static function short( string $s ): string {
        return mb_substr( $s, 0, 200 );
    }

    // -------------------------------------------------------------------------
    // Private helpers — load / save / schedule
    // dispatch(), outbox_state_for_user(), replay() are Tasks 6/8.
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

    // -------------------------------------------------------------------------
    // Dispatch chain (Task 6).
    // -------------------------------------------------------------------------

    /**
     * Replay the queued scan intent: clear bypass, (release stale half-state),
     * build payload, consent pre-check, reserve, resolve endpoint, submit, then
     * run the shared side-effects. All I/O is funneled through self::call() so
     * tests inject stubs via $deps; production calls dispatch() zero-arg.
     *
     * @param array $deps Optional dep-name => callable overrides (testability seam).
     * @return string One of: done|failed|pending|none.
     */
    public static function dispatch( array $deps = [] ): string {
        $entry = self::load();
        if ( ! $entry || in_array( $entry['status'] ?? '', [ 'done', 'failed' ], true ) ) {
            return $entry['status'] ?? 'none';
        }
        // due-guard BEFORE the lock: a not-yet-due tick must not take the lock or reserve.
        if ( (int) ( $entry['next_attempt_at'] ?? 0 ) > time() ) {
            return 'pending';
        }
        if ( ! self::acquire_lock() ) {
            return 'pending';
        }
        try {
            self::call( $deps, 'clear_bypass', [] ); // F-SEC: bound live bypass-token set to 1

            $now = time();
            if ( $entry['attempts'] >= self::MAX_ATTEMPTS || ( $now - (int) $entry['created_at'] ) > self::HORIZON ) {
                return self::fail( $entry, "couldn't reach the backend after repeated attempts - please try again.", $deps );
            }

            if ( ! empty( $entry['job_token'] ) ) { // release stale half-state from a prior pass / adopted token
                // Best-effort: the token may already be released/finalized (outage race with
                // submit_job/handle_failure) -> SaaS release() returns 409; swallow it.
                try { self::call( $deps, 'release', [ $entry['job_token'] ] ); } catch ( \Throwable $e ) {}
                $entry['job_token'] = null;
                self::save( $entry );
            }

            $intent  = $entry['intent'];
            $user_id = (int) ( $intent['user_id'] ?? 0 );

            [ $payload, $detector_typed, $bypass_token ] = self::call( $deps, 'build_payload', [ $intent ] );
            if ( self::call( $deps, 'consent_payload', [ $detector_typed, $intent['class_c_consent_given'] ?? '' ] ) !== null ) {
                return self::fail( $entry, "This scan needs optimizer-disable consent and can't be auto-dispatched - please start it again.", $deps );
            }

            try {
                $token = self::call( $deps, 'reserve', [ (int) $intent['page_count'], (int) ( $intent['extra_time_count'] ?? 0 ) ] );
            } catch ( \Throwable $e ) {
                return self::retry_or_fail( $entry, $e, $deps );
            }
            $entry['job_token'] = $token;
            self::save( $entry );            // crash-safe checkpoint
            $payload['job_token'] = $token;  // late-bind

            try {
                $railway_url = self::call( $deps, 'resolve_endpoint', [] );
            } catch ( \Throwable $e ) {
                return self::retry_or_fail( $entry, $e, $deps );
            }

            try {
                $result = self::call( $deps, 'submit', [ $railway_url, $payload ] );
            } catch ( \Throwable $e ) {
                $code = $e instanceof HttpException ? $e->get_status_code() : -1;
                if ( $code === 409 ) {
                    return self::fail( $entry, 'another scan on this account became active; this locally queued request was canceled.', $deps );
                }
                if ( self::is_retryable( $e ) ) {
                    return self::backoff( $entry, $e );
                }
                return self::fail( $entry, $e->getMessage(), $deps );
            }

            try {
                self::call( $deps, 'side_effects', [ $result, $intent, $detector_typed, $bypass_token, $railway_url, $token, $user_id ] );
            } catch ( \Throwable $e ) {
                // §10.6 safety net: worker job already created + charged -> keep it pollable, do NOT release.
                set_transient( 'cu_scanner_job_' . $user_id, [
                    'job_id'       => $result['job_id'] ?? '',
                    'job_token'    => $token,
                    'bypass_token' => $bypass_token,
                    'railway_url'  => $railway_url,
                ], 7200 );
                $entry['job_token'] = null; // ownership handed to the job transient
                return self::fail( $entry, $e->getMessage(), $deps );
            }

            $entry['job_token'] = null;
            self::done();
            return 'done';
        } finally {
            self::release_lock();
        }
    }

    /**
     * Run a dep stub when provided, else the real collaborator. The seam keeps
     * dispatch() free of direct HTTP/WP-data calls so unit tests stay hermetic.
     *
     * @param array  $deps Injected overrides.
     * @param string $name Dep name (see the real-default branch below).
     * @param array  $args Positional args for the dep.
     * @return mixed
     */
    private static function call( array $deps, string $name, array $args ) {
        if ( isset( $deps[ $name ] ) && is_callable( $deps[ $name ] ) ) {
            return ( $deps[ $name ] )( ...$args );
        }
        switch ( $name ) {
            case 'clear_bypass':
                ( new \CUScanner\Scanner\BypassManager() )->delete_all_tokens();
                return null;
            case 'resolve_endpoint':
                $s = new \CUScanner\Settings();
                return ( new \CUScanner\Admin\ScannerAjax() )->ensure_railway_url( $s, $s->get_api_key() );
            case 'reserve':
                $ak = ( new \CUScanner\Settings() )->get_api_key();
                return ( new \CUScanner\Api\WpserviceClient( CU_SCANNER_WPSERVICE_URL, $ak ) )
                    ->reserve_job( (int) $args[0], (int) $args[1] )['job_token'];
            case 'submit':
                $ak = ( new \CUScanner\Settings() )->get_api_key();
                return ( new \CUScanner\Api\RailwayClient( (string) $args[0], $ak ) )->submit_job( $args[1] );
            case 'release':
                $ak = ( new \CUScanner\Settings() )->get_api_key();
                ( new \CUScanner\Api\WpserviceClient( CU_SCANNER_WPSERVICE_URL, $ak ) )->release_credits( (string) $args[0] );
                return null;
            case 'build_payload':
                return ( new \CUScanner\Admin\ScannerAjax() )->build_submit_payload( $args[0] );
            case 'consent_payload':
                return ( new \CUScanner\Admin\ScannerAjax() )->class_c_consent_payload( $args[0], (string) $args[1] );
            case 'side_effects':
                // $args: 0=result, 1=intent, 2=detector_typed, 3=bypass_token, 4=railway_url, 5=job_token, 6=user_id
                return ( new \CUScanner\Admin\ScannerAjax() )->perform_submit_side_effects(
                    $args[0], $args[1], $args[2], (string) $args[3], (string) $args[4], (string) $args[5], (int) $args[6]
                );
        }
        // Code-review I-2: a mistyped dep name must fail loudly, not return null silently
        // (which would surface as an opaque "cannot unpack non-array" later).
        throw new \LogicException( sprintf( 'Outbox::call() unknown dependency: %s', esc_html( (string) $name ) ) );
    }

    /**
     * Terminal failure: release any held reservation (M2 — never strand credits),
     * clear bypass tokens, persist status=failed + short error, drop the cron.
     *
     * @param array  $entry Current outbox entry.
     * @param string $msg   Human-facing error.
     * @param array  $deps  Dep seam.
     * @return string Always 'failed'.
     */
    private static function fail( array $entry, string $msg, array $deps ): string {
        if ( ! empty( $entry['job_token'] ) ) {           // M2: never strand a reservation
            try { self::call( $deps, 'release', [ $entry['job_token'] ] ); } catch ( \Throwable $e ) {} // best-effort
            $entry['job_token'] = null;
        }
        try { self::call( $deps, 'clear_bypass', [] ); } catch ( \Throwable $e ) {}
        $entry['status']     = 'failed';
        $entry['last_error'] = self::short( $msg );
        self::save( $entry );
        wp_clear_scheduled_hook( self::CRON_HOOK );
        return 'failed';
    }

    /** Successful dispatch: drop the entry + cron. */
    private static function done(): void {
        delete_option( self::OPTION_KEY );
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /** Retry (backoff) when the error is retryable, else terminal fail. */
    private static function retry_or_fail( array $entry, \Throwable $e, array $deps ): string {
        return self::is_retryable( $e ) ? self::backoff( $entry, $e ) : self::fail( $entry, $e->getMessage(), $deps );
    }

    // -------------------------------------------------------------------------
    // Done-handoff state contract + cron entry point (Task 8).
    // -------------------------------------------------------------------------

    /**
     * Done-handoff state contract (§10.7).
     *
     * Returns one of:
     *   ['state'=>'queued',      'next_attempt_at'=>int]
     *   ['state'=>'failed',      'message'=>string]
     *   ['state'=>'dispatched',  'job_id'=>..., 'job_token'=>..., 'railway_url'=>...]
     *   ['state'=>'none']
     *
     * Priority: outbox entry (pending/failed) > job transient (dispatched) > none.
     * A 'failed' entry is never deleted by dispatch() — only done() deletes the option —
     * so this method correctly surfaces it. A later enqueue() overwrites it (enqueue()
     * only rejects on status==='pending').
     *
     * @param int $user_id The current WP user ID (used to look up the job transient).
     * @return array{state:string} State array with shape matching one of the four contracts above.
     */
    public static function outbox_state_for_user( int $user_id ): array {
        $entry = self::load();
        if ( $entry && ( $entry['status'] ?? '' ) === 'pending' ) {
            return [ 'state' => 'queued', 'next_attempt_at' => (int) ( $entry['next_attempt_at'] ?? 0 ) ];
        }
        if ( $entry && ( $entry['status'] ?? '' ) === 'failed' ) {
            return [ 'state' => 'failed', 'message' => (string) ( $entry['last_error'] ?? '' ) ];
        }
        $job = get_transient( 'cu_scanner_job_' . $user_id );
        if ( is_array( $job ) && ! empty( $job['job_id'] ) ) {
            return [
                'state'       => 'dispatched',
                'job_id'      => $job['job_id'],
                'job_token'   => $job['job_token'] ?? '',
                'railway_url' => $job['railway_url'] ?? '',
            ];
        }
        return [ 'state' => 'none' ];
    }

    /**
     * Cron entry point (CRON_HOOK = 'cu_scanner_outbox_replay').
     *
     * Delegates to dispatch() which internally guards against not-yet-due entries.
     */
    public static function replay(): void {
        self::dispatch();
    }
}

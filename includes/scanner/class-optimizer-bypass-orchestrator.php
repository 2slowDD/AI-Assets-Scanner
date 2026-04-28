<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

use CUScanner\Scanner\Strategies\AbstractOptimizerBypass;

/**
 * Coordinates the Class C disable/restore lifecycle for one scan.
 *
 * begin() snapshots and disables every registered strategy atomically.
 * complete() restores them in reverse order.
 *
 * Failure paths (spec §5):
 *   - normal completion: complete()
 *   - PHP fatal: register_shutdown_function (best-effort, not SIGKILL-safe)
 *   - stale-state self-heal: on_plugins_loaded() (next admin request after timeout)
 *   - watchdog: aias_optimizer_watchdog Action Scheduler / wp-cron event
 *   - refuse_to_start: begin() detects orphaned state and self-heals before throwing
 *
 * Spec §3.5, §5, §4.6.2.
 */
class OptimizerBypassOrchestrator {
    /** @var AbstractOptimizerBypass[] */
    private array $strategies;

    /** @param AbstractOptimizerBypass[] $strategies */
    public function __construct( array $strategies ) {
        $this->strategies = array_values( $strategies );
    }

    /**
     * Hook the failure-path restores. Called once at plugin init.
     */
    public static function init(): void {
        add_action( 'plugins_loaded', [ self::class, 'on_plugins_loaded' ], 0 );
        add_action( 'aias_optimizer_watchdog', [ self::class, 'on_watchdog' ], 10, 1 );
    }

    /**
     * @throws \RuntimeException on partial disable failure (state rolled back atomically)
     *                           or when refuse-to-start guard fires.
     */
    public function begin( string $scan_id, int $ttl_seconds = 1800 ): void {
        // Refuse-to-start guard: orphaned state from a prior crashed scan.
        if ( OptimizerState::is_orphaned() ) {
            $stale = OptimizerState::load();
            if ( $stale ) {
                $this->complete_with_loaded_state( $stale, 'refuse_to_start' );
            }
            throw new \RuntimeException(
                'Previous scan did not clean up. Optimizer state has been restored. Please retry.'
            );
        }

        $snapshots = [];
        $disabled  = [];  // [ [ strategy, snapshot ] ] — for rollback in reverse.
        try {
            foreach ( $this->strategies as $strategy ) {
                $snap = $strategy->snapshot();
                $snapshots[ $strategy->slug() ] = $snap;
                $strategy->disable();
                $disabled[] = [ $strategy, $snap ];
                EventEmitter::emit( 'optimizer_disabled', 'operational', [
                    'plugin'        => $strategy->slug(),
                    'strategy'      => 'variant_b',
                    'snapshot_hash' => substr( hash( 'sha256', (string) \json_encode( $snap ) ), 0, 16 ),
                    'scan_id'       => $scan_id,
                ], $scan_id );
            }
        } catch ( \Throwable $e ) {
            // Atomic rollback in reverse order; swallow restore-time errors here.
            foreach ( array_reverse( $disabled ) as [ $strategy, $snap ] ) {
                try {
                    $strategy->restore( $snap );
                } catch ( \Throwable $_ ) {
                    // Swallow during atomic rollback — best effort.
                }
            }
            OptimizerState::clear();
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- composing a new exception message for re-throw, not output. Caller renders via wp_send_json_error or logs server-side.
            throw new \RuntimeException(
                'Optimizer disable aborted: ' . $e->getMessage(),
                0,
                $e
            );
        }

        OptimizerState::save( $scan_id, $snapshots, $ttl_seconds );

        // Failure-path 1: PHP shutdown hook (best-effort).
        register_shutdown_function( static function () use ( $scan_id ) {
            $state = OptimizerState::load();
            if ( ! $state ) return;
            if ( ( $state['scan_id'] ?? '' ) !== $scan_id ) return;
            self::build_default_orchestrator()->complete_with_loaded_state( $state, 'shutdown' );
        } );

        // Failure-path 4: watchdog — Action Scheduler if available, wp-cron fallback.
        $watchdog_at = time() + $ttl_seconds + 300;
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( $watchdog_at, 'aias_optimizer_watchdog', [ 'scan_id' => $scan_id ] );
        } else {
            wp_schedule_single_event( $watchdog_at, 'aias_optimizer_watchdog', [ $scan_id ] );
        }
    }

    public function complete( string $source ): void {
        $state = OptimizerState::load();
        if ( ! $state ) {
            return;
        }
        $this->complete_with_loaded_state( $state, $source );
    }

    /**
     * Restore from an explicit state payload. Used by failure paths
     * (shutdown, stale_state, watchdog, refuse_to_start, manual).
     */
    public function complete_with_loaded_state( array $state, string $source ): void {
        $any_failed = false;
        foreach ( array_reverse( $this->strategies ) as $strategy ) {
            $snap = $state['snapshots'][ $strategy->slug() ] ?? null;
            if ( $snap === null ) {
                continue;
            }
            try {
                $strategy->restore( $snap );
                EventEmitter::emit( 'optimizer_restored', 'operational', [
                    'plugin'   => $strategy->slug(),
                    'strategy' => 'variant_b',
                    'scan_id'  => (string) ( $state['scan_id'] ?? '' ),
                    'source'   => $source,
                ], (string) ( $state['scan_id'] ?? '' ) );
            } catch ( \Throwable $_ ) {
                $any_failed = true;
            }
        }
        if ( ! $any_failed ) {
            OptimizerState::clear();
        }
    }

    // ─── Failure-path entry points ────────────────────────────────────

    public static function on_plugins_loaded(): void {
        if ( ! OptimizerState::is_orphaned() ) {
            return;
        }
        $state = OptimizerState::load();
        if ( ! $state ) {
            return;
        }
        self::build_default_orchestrator()->complete_with_loaded_state( $state, 'stale_state' );
    }

    public static function on_watchdog( $scan_id ): void {
        $state = OptimizerState::load();
        if ( ! $state ) return;
        if ( ( $state['scan_id'] ?? '' ) !== (string) $scan_id ) return;
        self::build_default_orchestrator()->complete_with_loaded_state( $state, 'watchdog' );
    }

    // ─── Internal: build orchestrator from currently-active Class C plugins ────

    public static function build_default_orchestrator(): self {
        $strategies = [];
        $detector   = new PluginDetector();
        foreach ( $detector->detect_typed() as $entry ) {
            if ( ( $entry['class'] ?? '' ) !== 'C' ) {
                continue;
            }
            $method = $entry['disable_method'] ?? '';
            if ( $method === '' ) {
                continue;
            }
            try {
                $strategies[] = StrategyFactory::for_method( $method );
            } catch ( \InvalidArgumentException $_ ) {
                // Unknown method (Phase 4 may not have shipped a strategy for this
                // optimizer yet) — skip rather than crash the failure-path restore.
            }
        }
        return new self( $strategies );
    }
}

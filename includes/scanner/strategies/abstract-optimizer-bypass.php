<?php
namespace CUScanner\Scanner\Strategies;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for Class C optimizer-disable strategies.
 *
 * Each strategy snapshots the optimizer's settings before scan, flips the
 * documented master toggle to "off", and restores byte-identical state
 * afterward. Restores must be idempotent: replaying the same snapshot
 * after a successful restore is a no-op.
 *
 * Spec: §4.5 Class C lifecycle, §3 optimizer matrix.
 */
abstract class AbstractOptimizerBypass {
    /**
     * Stable plugin slug used in audit events and as the snapshots-array key.
     * Matches the `disable_method` field in PluginDetector::OPTIMIZERS.
     */
    abstract public function slug(): string;

    /**
     * Capture the current state of the optimizer's documented settings.
     * Return value MUST be JSON-serializable so the orchestrator can persist
     * it via update_option for crash-recovery (spec §4.6.2).
     *
     * Convention: keys absent from the optimizer's option array are recorded
     * as null in the snapshot. restore() then unsets them rather than writing
     * null, preserving "absence" rather than introducing a new null value.
     *
     * @return array<string, mixed>
     */
    abstract public function snapshot(): array;

    /**
     * Apply the disable. MUST throw on failure so the orchestrator can abort
     * atomically and roll back already-disabled strategies (spec §3.5).
     */
    abstract public function disable(): void;

    /**
     * Restore byte-identical to the snapshot. Idempotent.
     *
     * @param array<string, mixed> $snapshot Output of an earlier snapshot() call.
     */
    abstract public function restore( array $snapshot ): void;
}

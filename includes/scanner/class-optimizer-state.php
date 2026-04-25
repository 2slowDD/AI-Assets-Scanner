<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Persistent storage primitive for the Class C optimizer-disable lifecycle.
 *
 * Stores per-scan snapshot blobs in a single option (autoload=false) so the
 * payload survives `wp_cache_flush()` calls triggered by optimizer
 * deactivation hooks. Manual TTL via `expires_at` field — orphan detection
 * runs lazily on the next admin request that calls `is_orphaned()`.
 *
 * Spec §4.6.2.
 */
class OptimizerState {
    public const OPTION = 'aias_optimizer_state';

    /**
     * @param string               $scan_id      12-16 hex chars
     * @param array<string, array> $snapshots    slug => strategy snapshot blob
     * @param int                  $ttl_seconds  scan_timeout + restore margin
     */
    public static function save( string $scan_id, array $snapshots, int $ttl_seconds ): void {
        $now = time();
        update_option( self::OPTION, [
            'scan_id'    => $scan_id,
            'created_at' => $now,
            'expires_at' => $now + $ttl_seconds,
            'snapshots'  => $snapshots,
        ], false );
    }

    /** @return array{scan_id:string,created_at:int,expires_at:int,snapshots:array<string,array>}|null */
    public static function load(): ?array {
        $value = get_option( self::OPTION, null );
        return is_array( $value ) ? $value : null;
    }

    public static function clear(): void {
        delete_option( self::OPTION );
    }

    /**
     * True when state exists AND `time() > expires_at`. Also returns true if
     * the persisted record is missing its `expires_at` field — defensive
     * against corrupted/legacy storage.
     */
    public static function is_orphaned(): bool {
        $state = self::load();
        if ( ! $state ) {
            return false;  // no state to orphan
        }
        if ( ! isset( $state['expires_at'] ) ) {
            return true;
        }
        return time() > (int) $state['expires_at'];
    }
}

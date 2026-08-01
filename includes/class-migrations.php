<?php
namespace CUScanner;

defined( 'ABSPATH' ) || exit;

/**
 * One-time data migrations, integer-ladder versioned via the autoloaded
 * `cu_scanner_db_version` option (~10 bytes; read every request, so
 * autoloading it is correct). Runs on `plugins_loaded` — AAS updates are
 * delivered by PrivateUpdater (manifest + ZIP only; nothing executes
 * post-swap), so migrations must self-run at runtime.
 *
 * CONSTRAINT: every mN MUST be idempotent — a later failing step re-runs
 * all earlier steps on the next request.
 */
class Migrations {
    private const DB_VERSION     = 1;
    private const VERSION_OPTION = 'cu_scanner_db_version';

    public static function maybe_run(): void {
        if ( wp_installing() ) {
            return; // plugins_loaded also fires during core install/upgrade
        }
        $at = (int) get_option( self::VERSION_OPTION, 0 );
        if ( $at >= self::DB_VERSION ) {
            return; // O(1): autoloaded option — alloptions array lookup, no query
        }
        // `$at < 1` is ladder scaffolding — redundant while DB_VERSION = 1,
        // kept deliberately as the pattern for m2 (`$at < 2`) and beyond.
        if ( $at < 1 && ! self::m1_scan_history_autoload_off() ) {
            return; // failed — version NOT recorded; next request retries
        }
        update_option( self::VERSION_OPTION, self::DB_VERSION ); // autoloaded (default) — intentional
    }

    private static function m1_scan_history_autoload_off(): bool {
        return false; // implemented in Tasks 2-3 (TDD)
    }

    private static function debug_log( string $msg ): void {
        if ( cu_scanner_debug_enabled() ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostic; gated by cu_scanner_debug_enabled() (default OFF).
            error_log( '[AI Assets Scanner] Migrations: ' . $msg );
        }
    }
}

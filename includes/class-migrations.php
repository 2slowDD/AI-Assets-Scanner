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
        global $wpdb;

        $like = $wpdb->esc_like( 'cu_scanner_json_' ) . '%';

        // Enumerate ACTUAL rows (prefix-LIKE uses the option_name index — no full-table
        // scan; catches orphaned JSON rows with no history record).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-shot migration over wildcard option keys; cache layer not relevant; table name from $wpdb->options is internal.
        $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
        // Checked IMMEDIATELY after the query: wpdb::last_error is per-query — any
        // subsequent query wipes it (wpdb::query() -> flush()).
        if ( '' !== $wpdb->last_error ) {
            self::debug_log( 'm1 enumeration SELECT failed: ' . $wpdb->last_error );
            return false;
        }
        $names[] = 'cu_scanner_history';

        $placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
        // Stored 'no' is non-autoload on every supported version (6.2 loader:
        // autoload='yes'; 6.6+ loader: IN ('yes','on','auto-on','auto')).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one-shot migration; placeholder list is built from count(), values passed as array; table name internal.
        $updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name IN ($placeholders)", $names ) );
        // STRICT false check is load-bearing: an idempotent re-run affects 0 rows and
        // query() returns 0 — `! $updated` would misread that success as failure.
        if ( false === $updated ) {
            self::debug_log( 'm1 UPDATE failed: ' . $wpdb->last_error );
            return false;
        }
        wp_cache_delete( 'alloptions', 'options' );

        // POSITIVE verification — success is asserted by re-reading the DB (the same
        // invariant as the release AC), never inferred from the absence of errors.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-shot migration; table name internal.
        $remaining = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name = %s ) AND autoload IN ( 'yes', 'on', 'auto-on', 'auto' )", $like, 'cu_scanner_history' ) );
        if ( null === $remaining || '' !== $wpdb->last_error ) {
            self::debug_log( 'm1 post-verify query failed: ' . $wpdb->last_error );
            return false; // a failed COUNT must not read as 0
        }
        if ( 0 !== (int) $remaining ) {
            self::debug_log( 'm1 post-verify failed: ' . (int) $remaining . ' rows still autoloading' );
            return false;
        }
        return true;
    }

    private static function debug_log( string $msg ): void {
        if ( cu_scanner_debug_enabled() ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostic; gated by cu_scanner_debug_enabled() (default OFF).
            error_log( '[AI Assets Scanner] Migrations: ' . $msg );
        }
    }
}

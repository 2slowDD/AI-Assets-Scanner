<?php
/**
 * Uninstall handler — removes plugin options and transients on plugin deletion.
 *
 * Triggered by WordPress when the user deletes the plugin from the Plugins
 * screen. Preserves the saved API key so reinstalling the plugin can resume an
 * existing active key instead of auto-registering a different free key. Removes
 * other plugin options and any plugin-prefixed transients still in the options
 * table.
 *
 * File-scope variables use the `ai_assets_scanner_` plugin prefix (derived
 * from the plugin Text Domain `AI-Assets-Scanner`) even though the option
 * keys themselves are legacy `cu_scanner_*` — Plugin Check's
 * NonPrefixedVariableFound sniff accepts only the canonical text-domain
 * prefix here, not abbreviations like `aias_` or the legacy `cu_scanner_`.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! current_user_can( 'delete_plugins' ) ) {
	return;
}

$ai_assets_scanner_fixed_options = array(
	'cu_scanner_railway_url',
	'cu_scanner_http_auth',
	'cu_scanner_active_tokens',
	'cu_scanner_secret',
	'cu_scanner_history',
	'aias_last_result',        // 1.5.4 — background-restore payload (per-URL table + 12-char scan_id).
	'aias_last_seen_scan_id',  // menu-badge last-seen-scan marker.
	'aias_dismissed_warnings', // per-scan broken-banner dismissals.
);
foreach ( $ai_assets_scanner_fixed_options as $ai_assets_scanner_option_name ) {
	delete_option( $ai_assets_scanner_option_name );
}

global $wpdb;

// Per-job snapshot options: cu_scanner_json_<job_id>.
$ai_assets_scanner_json_like = $wpdb->esc_like( 'cu_scanner_json_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-shot uninstall cleanup of wildcard option keys; cache layer not relevant; table name from $wpdb->options is internal.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_assets_scanner_json_like ) );

// Plugin-prefixed transients (active job state, history-deleted notice, pending-token state).
$ai_assets_scanner_transient_like         = $wpdb->esc_like( '_transient_cu_scanner_' ) . '%';
$ai_assets_scanner_transient_timeout_like = $wpdb->esc_like( '_transient_timeout_cu_scanner_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-shot uninstall cleanup of plugin-prefixed transients; cache layer not relevant; table name from $wpdb->options is internal.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $ai_assets_scanner_transient_like, $ai_assets_scanner_transient_timeout_like ) );

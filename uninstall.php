<?php
/**
 * Uninstall handler — removes plugin options and transients on plugin deletion.
 *
 * Triggered by WordPress when the user deletes the plugin from the Plugins
 * screen. Removes every `cu_scanner_*` option (including the encrypted
 * HTTP-auth blob, plaintext API key, scanner secret, active bypass tokens,
 * scan history, and per-job snapshot options) plus any plugin-prefixed
 * transients still in the options table.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! current_user_can( 'delete_plugins' ) ) {
	return;
}

$cu_scanner_fixed_options = array(
	'cu_scanner_api_key',
	'cu_scanner_railway_url',
	'cu_scanner_http_auth',
	'cu_scanner_active_tokens',
	'cu_scanner_secret',
	'cu_scanner_history',
);
foreach ( $cu_scanner_fixed_options as $cu_scanner_option_name ) {
	delete_option( $cu_scanner_option_name );
}

global $wpdb;

// Per-job snapshot options: cu_scanner_json_<job_id>.
$cu_scanner_json_like = $wpdb->esc_like( 'cu_scanner_json_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-shot uninstall cleanup of wildcard option keys; cache layer not relevant; table name from $wpdb->options is internal.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $cu_scanner_json_like ) );

// Plugin-prefixed transients (active job state, history-deleted notice, pending-token state).
$cu_scanner_transient_like         = $wpdb->esc_like( '_transient_cu_scanner_' ) . '%';
$cu_scanner_transient_timeout_like = $wpdb->esc_like( '_transient_timeout_cu_scanner_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-shot uninstall cleanup of plugin-prefixed transients; cache layer not relevant; table name from $wpdb->options is internal.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $cu_scanner_transient_like, $cu_scanner_transient_timeout_like ) );

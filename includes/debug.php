<?php
/**
 * AAS debug-log gate. Default OFF: AAS writes diagnostic error_log lines ONLY when
 * the operator opts in via `define( 'CU_SCANNER_DEBUG', true );` in wp-config.php.
 * Real-error (exception-catch) logs stay ungated elsewhere — this gates DIAGNOSTICS only.
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'cu_scanner_debug_enabled' ) ) {
    function cu_scanner_debug_enabled(): bool {
        return defined( 'CU_SCANNER_DEBUG' ) && CU_SCANNER_DEBUG;
    }
}

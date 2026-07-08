<?php
/**
 * Standalone: php tests/scanner-ajax-warning-needed-test.php
 * Covers spec §3.3 warning_needed OR-extension (Task 4 / AC-5-adjacent: outcome logic unchanged).
 *
 * ScannerAjax loads standalone (see tests/bypass-suffix-map-persist-test.php docblock):
 * it extends nothing, declares no class constants / property initializers, and its
 * `use` aliases + method type-hints are lazy (never resolved at class-declaration
 * time). compute_warning_needed() makes zero WP calls, so no WP stubs are needed —
 * only ABSPATH must be defined before require (guard at class-scanner-ajax.php:4).
 */
define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../admin/class-scanner-ajax.php';

use CUScanner\Admin\ScannerAjax;

function aias_assert( $cond, $msg ) { if ( ! $cond ) { throw new RuntimeException( 'FAIL: ' . $msg ); } }

$clean   = [ 'outcome' => 'class_a_clean', 'security_stacks' => [] ];
$stacked = [ 'outcome' => 'class_a_clean', 'security_stacks' => [ 'cloudflare' ] ];
$bc      = [ 'outcome' => 'class_bc_only', 'security_stacks' => [] ];
$legacy  = [ 'outcome' => 'class_a_clean' ]; // pre-Task-2 cached shape: key absent

aias_assert( ScannerAjax::compute_warning_needed( [ $clean ] ) === false, 'all clean -> false' );
aias_assert( ScannerAjax::compute_warning_needed( [ $bc ] ) === true, 'non-clean outcome -> true (existing behavior)' );
aias_assert( ScannerAjax::compute_warning_needed( [ $stacked ] ) === true, 'security stack on clean outcome -> true (NEW)' );
aias_assert( ScannerAjax::compute_warning_needed( [ $legacy ] ) === false, 'legacy cached shape (no key) -> false, no notice/throw' );
aias_assert( ScannerAjax::compute_warning_needed( [] ) === false, 'empty -> false' );
aias_assert( ScannerAjax::compute_warning_needed( [ $clean, $stacked ] ) === true, 'mixed hosts: one stacked -> true' );
aias_assert( ScannerAjax::compute_warning_needed( [ $clean, $legacy ] ) === false, 'mixed hosts: clean + legacy (no key) -> false' );

// --- strip_to_whitelist() passthrough (spec §3.3): security_stacks must survive the
// AC-N2-SSRF allowlist (values are internal SECURITY_STACKS registry ids, a closed enum —
// never raw response content), while genuinely unknown keys are still stripped.
// Reached via the class's established __test_* seam (strip_to_whitelist is private).
$stripped = ScannerAjax::__test_strip_to_whitelist( [
    'outcome'         => 'class_a_clean',
    'security_stacks' => [ 'cloudflare' ],
    'evil_raw_header' => 'x-secret: leaked-origin-header',
] );
aias_assert( ( $stripped['security_stacks'] ?? null ) === [ 'cloudflare' ], 'security_stacks survives the allowlist with value intact (spec 3.3)' );
aias_assert( ! array_key_exists( 'evil_raw_header', $stripped ), 'unknown/raw key still stripped (AC-N2-SSRF intent preserved)' );
aias_assert( ( $stripped['outcome'] ?? null ) === 'class_a_clean', 'pre-existing allowlisted key unaffected' );

echo "scanner-ajax-warning-needed-test ... ok\n";
exit( 0 );

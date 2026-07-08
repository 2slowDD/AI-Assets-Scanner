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

echo "scanner-ajax-warning-needed-test ... ok\n";
exit( 0 );

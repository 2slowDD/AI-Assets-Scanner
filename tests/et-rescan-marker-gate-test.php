<?php
/**
 * Standalone: php tests/et-rescan-marker-gate-test.php
 *
 * FU-ET-STAMP-SEVERS-RATCHET — the ET Result Ratchet's engagement gate must fire on
 * an ET rescan INDEPENDENTLY of the worker billing stamp `extra_time_charged`.
 * Worker commit 7a4a161 (2026-07-05, W1) made that stamp conditional on delivered
 * billable value, so a zero-yield ET rescan (S:0 A:0 — the exact case the ratchet
 * floor exists for) arrives UN-stamped: is_et_rescan() returned false → the ratchet
 * was skipped AND persist_r_orig() clobbered the baseline. This pins the fix: AAS
 * detects the ET rescan from its own submit-time intent (extra_time_urls), persisted
 * as a job-keyed marker, mirroring the reviewed cu_scanner_bypass_map_<job_id> pattern.
 *
 * ScannerAjax loads standalone (see tests/bypass-suffix-map-persist-test.php docblock).
 * The ratchet-gate helpers call get_transient/get_option, stubbed below with an
 * in-memory store; new ScannerAjax() has no constructor side effects (register() holds
 * the add_action calls, and is not called here).
 */
define( 'ABSPATH', __DIR__ );

$GLOBALS['__aias_transients'] = [];
function get_transient( $k ) { return $GLOBALS['__aias_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__aias_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__aias_transients'][ $k ] ); return true; }
function get_option( $k, $default = false ) { return $default; } // ratchet default-ON

require __DIR__ . '/../admin/class-scanner-ajax.php';

use CUScanner\Admin\ScannerAjax;

function aias_assert( $cond, string $msg ): void {
    if ( ! $cond ) { throw new RuntimeException( 'FAIL: ' . $msg ); }
}

$ajax = new ScannerAjax();

// Zero-yield ET rescan: pages carry NO extra_time_charged (the regression's trigger).
$unstamped = [ [ 'url' => 'https://pinadventures.com?perfmattersoff', 'assets' => [] ] ];
// Old detection path: a page the worker DID bill for ET (delivered value).
$stamped   = [ [ 'url' => 'https://x.test', 'extra_time_charged' => true ] ];

// ── AC-1: job-keyed marker — one key helper shared by writer + reader (parity). ──
aias_assert( ScannerAjax::et_rescan_marker_key( 'J1' ) === 'cu_scanner_et_rescan_J1', 'marker key shape' );
aias_assert( $ajax->__test_et_rescan_requested( 'J1' ) === false, 'no marker -> false' );
set_transient( ScannerAjax::et_rescan_marker_key( 'J1' ), 1, 7200 );
aias_assert( $ajax->__test_et_rescan_requested( 'J1' ) === true, 'marker set -> true' );
aias_assert( $ajax->__test_et_rescan_requested( 'J2' ) === false, 'other job unaffected' );

// ── AC-trigger: the submit-time predicate that decides whether to set the marker. ──
aias_assert( ScannerAjax::intent_requests_extra_time( [ 'extra_time_urls' => [ 'u' ] ] ) === true, 'ET urls -> true' );
aias_assert( ScannerAjax::intent_requests_extra_time( [ 'extra_time_urls' => [] ] ) === false, 'empty ET list -> false' );
aias_assert( ScannerAjax::intent_requests_extra_time( [] ) === false, 'no key -> false' );

// ── AC-2: the gate ENGAGES on a zero-yield ET rescan (marker set, no billing stamp). ──
aias_assert( $ajax->__test_is_et_rescan( $unstamped ) === false, 'unstamped pages alone -> is_et false (this WAS the regression)' );
aias_assert( $ajax->__test_resolve_is_et_rescan( $unstamped, 'J1' ) === true, 'unstamped + marker -> gate TRUE (the fix)' );
aias_assert( $ajax->__test_resolve_is_et_rescan( $unstamped, 'J2' ) === false, 'unstamped + no marker -> gate false (a normal scan)' );

// ── AC-back-compat: the billing stamp still engages the gate. ──
aias_assert( $ajax->__test_resolve_is_et_rescan( $stamped, 'J2' ) === true, 'stamped pages -> gate true (old path preserved)' );

// ── AC-3: persist is SKIPPED for the zero-yield ET rescan → no baseline clobber. ──
aias_assert( $ajax->__test_should_persist_r_orig( $unstamped, 'J1' ) === false, 'ET rescan (marker) -> do NOT persist (no clobber)' );
aias_assert( $ajax->__test_should_persist_r_orig( $unstamped, 'J2' ) === true, 'normal scan -> DO persist baseline' );

echo "et-rescan-marker-gate-test ... ok\n";
exit( 0 );

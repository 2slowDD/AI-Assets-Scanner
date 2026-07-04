<?php
/**
 * Seam-proof: AIAS_Scan_Status cancel-aware / blocked-relax Credits display
 * (FU-BILLING-BLOCKED-NOOPT E3, spec AC-5). Standalone — no PHPUnit/wp_mock:
 *
 *   php tests/scan-status-cancel-aware-test.php
 *
 * Prints "scan-status cancel-aware credits ok" + exits 0 on success; throws
 * RuntimeException (non-zero exit) on the first mismatch.
 */

define( 'ABSPATH', __DIR__ );

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

// Stub of includes/class-broken-banner.php — classify() only consumes the
// category ('bot' selects the 'blocked' class) and a human phrase.
class AIAS_Broken_Banner {
	public static function reason_category( string $reason ): string {
		return in_array( $reason, array( 'tier1_http_429', 'tier1_http_403' ), true ) ? 'bot' : 'error';
	}
	public static function reason_phrase( string $reason ): string {
		return $reason;
	}
}

require __DIR__ . '/../includes/class-scan-status.php';

function assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

$noopt_tally = array( 'safe' => 0, 'aggressive' => 0, 'needed' => 2 );
$agg_tally   = array( 'safe' => 0, 'aggressive' => 3, 'needed' => 1 );

$clean_noopt   = array( 'url' => 'https://a/', 'status' => 'done', 'assets' => array( array( 'handle' => 'h' ) ), 'broken_devices' => array() );
$blocked_noopt = array(
	'url' => 'https://b/', 'status' => 'done', 'assets' => array( array( 'handle' => 'h' ) ),
	'broken_devices' => array( array( 'device' => 'desktop', 'reason' => 'tier1_http_429' ) ),
);
$partial_noopt = array(
	'url' => 'https://p/', 'status' => 'done', 'assets' => array( array( 'handle' => 'h' ) ),
	'broken_devices' => array( array( 'device' => 'mobile', 'reason' => 'render_timeout' ) ),
);
$blocked_et = $blocked_noopt;
$blocked_et['extra_time_charged'] = true;

// ── AC-5 core: NON-cancelled display (terminal_source null/omitted) ──
assert_same( 0, AIAS_Scan_Status::page_credit( $clean_noopt, $noopt_tally ), 'ok-class S:0 A:0 non-ET row displays 0 (preserved)' );
assert_same( 0, AIAS_Scan_Status::page_credit( $blocked_noopt, $noopt_tally ), 'blocked non-ET S:0 A:0 row displays 0 (the E3 relax)' );
assert_same( 0, AIAS_Scan_Status::page_credit( $partial_noopt, $noopt_tally ), 'partial non-ET S:0 A:0 row displays 0 (the E3 relax)' );
assert_same( 1, AIAS_Scan_Status::page_credit( $blocked_noopt, $agg_tally ), 'A>0 blocked row displays 1 (unchanged)' );
assert_same( 2, AIAS_Scan_Status::page_credit( $blocked_et, $noopt_tally ), 'ET blocked S:0 A:0 row displays 2 = base+ET (ET exempt, unchanged)' );
assert_same( 1, AIAS_Scan_Status::page_credit( $clean_noopt, null ), 'null tally keeps legacy 1-per-ok (no zeroing without by_page)' );

// ── AC-5 cancel-aware (M1 ruling): terminal_source 'user_cancel' skips ALL display-zeroing ──
assert_same( 1, AIAS_Scan_Status::page_credit( $clean_noopt, $noopt_tally, 'user_cancel' ), 'cancelled scan: clean S:0 A:0 done row displays 1' );
assert_same( 1, AIAS_Scan_Status::page_credit( $blocked_noopt, $noopt_tally, 'user_cancel' ), 'cancelled scan: blocked S:0 A:0 done row displays 1' );
assert_same( 2, AIAS_Scan_Status::page_credit( $blocked_et, $noopt_tally, 'user_cancel' ), 'cancelled scan: ET row display unchanged (the spec-§8 wrinkle — ET rows are excluded from the row-sum assert)' );

// Non-cancel terminal sources render identically to null (enum accepted but inert).
assert_same( 0, AIAS_Scan_Status::page_credit( $blocked_noopt, $noopt_tally, 'failed' ), 'failure-partial keeps the noopt display-zero' );

// ── AC-5 row-sum (r2-m1: NON-ET fixture): cancelled-scan rows sum to the E2 charge ──
$err_page  = array(
	'url' => 'https://c/', 'status' => 'error', 'assets' => array(),
	'broken_devices' => array( array( 'device' => 'desktop', 'reason' => 'render_timeout' ) ),
);
$pages_raw = array( $clean_noopt, $blocked_noopt, $err_page );
$by_page   = array( 0 => $noopt_tally, 1 => $noopt_tally ); // error pages absent — mirrors CuJsonBuilder::build()

$rows = AIAS_Scan_Status::build_pages( $pages_raw, $by_page, true, 'user_cancel' );
assert_same( 1, $rows[0]['credits'], 'cancelled scan row 0 (clean noopt done) displays 1' );
assert_same( 1, $rows[1]['credits'], 'cancelled scan row 1 (blocked noopt done) displays 1' );
assert_same( 'cancelled', $rows[2]['status_class'], 'not-scanned force-zero label unchanged on a cancelled scan' );
assert_same( 0, $rows[2]['credits'], 'not-scanned force-zero credits unchanged on a cancelled scan' );
$charged_by_worker = 2; // E2: /cancel bills ALL done pages (2); the error page bills 0.
assert_same( $charged_by_worker, $rows[0]['credits'] + $rows[1]['credits'] + $rows[2]['credits'], 'row sum equals the E2 charge on a user-cancelled scan (M1)' );

// ── Same fixture, NON-cancelled partial (failed): zeroing applies, force-zero unchanged ──
$rows_fail = AIAS_Scan_Status::build_pages( $pages_raw, $by_page, true, 'failed' );
assert_same( 0, $rows_fail[0]['credits'], 'failed-partial row 0 noopt-zeroed' );
assert_same( 0, $rows_fail[1]['credits'], 'failed-partial row 1 (blocked) noopt-zeroed (E3 relax)' );
assert_same( 'cancelled', $rows_fail[2]['status_class'], 'not-scanned force-zero label unchanged on a failed partial' );
assert_same( 0, $rows_fail[2]['credits'], 'not-scanned force-zero credits unchanged on a failed partial' );

// ── Back-compat: the 3-arg legacy call shape (MenuBadge path / pre-E3 callers) ──
$rows_legacy = AIAS_Scan_Status::build_pages( $pages_raw, $by_page, true );
assert_same( 0, $rows_legacy[0]['credits'], 'omitted terminal_source behaves as null (normal zeroing)' );

echo "scan-status cancel-aware credits ok\n";

<?php
/**
 * Seam-proof: FU-ABSENT-SAFE B2 (review fix) — per-URL bypass-suffix map persist +
 * read-back key matching. Standalone: php tests/bypass-suffix-map-persist-test.php
 * Prints "bypass-suffix-map persist ok" + exit 0; throws on first mismatch.
 *
 * Proves the EXTERNAL-scan fix: the submit-side map is keyed by the FINAL scan URL
 * (pages[].url — resolved + scheme + appended bypass suffix) which the Railway worker
 * echoes back VERBATIM, so the result-side lookup stamps BOTH internal and external
 * rows. A full two-AJAX-handler + transient integration test is disproportionate; the
 * entire correctness risk is in the map-build + key-match, which these two pure static
 * seams isolate (behavior-preserving extraction — perform_submit_side_effects() and
 * do_build_result() call the same two methods this file exercises directly).
 *
 * ScannerAjax loads standalone: it extends nothing, declares no class constants /
 * property initializers, and its `use` aliases + method type-hints are lazy (never
 * resolved at class-declaration time). build_bypass_map()/stamp_bypass_suffixes() make
 * zero WP calls, so no WP stubs are needed.
 */
define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../admin/class-scanner-ajax.php';

use CUScanner\Admin\ScannerAjax;

function aias_assert( $cond, string $msg ): void {
    if ( ! $cond ) { throw new RuntimeException( "FAIL: {$msg}" ); }
}

// Reshaped payload pages exactly as reshape_page_specs() builds them: 'url' is the
// FINAL scan URL (resolved + scheme + appended bypass suffix), 'bypass_suffixes' the
// applied list. INTERNAL row (host suffix), EXTERNAL row (probe suffix), and a THIRD
// external row with NO suffix (empty → must NOT be stored → fail-closed no note).
$internal_url = 'https://ownsite.test/about?nowprocket&nowpcu';
$external_url = 'https://client.example/pricing?LSCWP_CTRL=before_optm';
$noopt_url    = 'https://client2.example/contact';

$pages_sent = [
    [ 'url' => $internal_url, 'bypass_token' => 't', 'bypass_suffixes' => [ 'nowprocket', 'nowpcu' ], 'extra_time' => false, 'submitted_url' => $internal_url ],
    [ 'url' => $external_url, 'bypass_token' => 't', 'bypass_suffixes' => [ 'LSCWP_CTRL=before_optm' ], 'extra_time' => false, 'submitted_url' => $external_url ],
    [ 'url' => $noopt_url,    'bypass_token' => 't', 'bypass_suffixes' => [], 'extra_time' => false, 'submitted_url' => $noopt_url ],
];

$map = ScannerAjax::build_bypass_map( $pages_sent );

// ---- build side: map keyed by the exact final scan URL; empty-suffix URL excluded ----
aias_assert( isset( $map[ $internal_url ] ), 'internal URL must be in the map' );
aias_assert( isset( $map[ $external_url ] ), 'EXTERNAL URL must be in the map (the whole point of the review fix)' );
aias_assert( ! isset( $map[ $noopt_url ] ), 'URL with empty suffixes must be ABSENT (fail-closed)' );
aias_assert( $map[ $internal_url ] === [ 'nowprocket', 'nowpcu' ], 'internal suffixes preserved verbatim' );
aias_assert( $map[ $external_url ] === [ 'LSCWP_CTRL=before_optm' ], 'external suffixes preserved verbatim' );

// ---- result side: worker echoes pages[].url VERBATIM. Rows arrive in a DIFFERENT
// order, reindexed (filter_real_pages array_values), with NO bypass_suffixes field —
// the raw worker shape. Stamp must match on the url STRING, not the index. ----
$pages_raw = [
    [ 'url' => $external_url, 'status' => 'done', 'assets' => [] ],
    [ 'url' => $noopt_url,    'status' => 'done', 'assets' => [] ],
    [ 'url' => $internal_url, 'status' => 'done', 'assets' => [] ],
    [ 'url' => 'https://client.example/unseen', 'status' => 'done', 'assets' => [] ], // never submitted
];

$stamped = ScannerAjax::stamp_bypass_suffixes( $pages_raw, $map );

// External row MUST now carry its suffix — this is the regression the review fix closes.
aias_assert( ( $stamped[0]['bypass_suffixes'] ?? null ) === [ 'LSCWP_CTRL=before_optm' ], 'EXTERNAL result row must be stamped from the persisted map' );
// noopt row: no suffix ever applied → must remain unstamped.
aias_assert( ! isset( $stamped[1]['bypass_suffixes'] ), 'noopt row must stay unstamped (fail-closed no note)' );
// Internal row MUST carry its suffix (regression parity with the old same-host behavior).
aias_assert( ( $stamped[2]['bypass_suffixes'] ?? null ) === [ 'nowprocket', 'nowpcu' ], 'internal result row must be stamped' );
// A row whose URL never appeared in the map → unstamped.
aias_assert( ! isset( $stamped[3]['bypass_suffixes'] ), 'unknown URL must stay unstamped (fail-closed)' );

// ---- defensive: dirty map (non-string leaves, non-array value) must not crash/leak ----
$dirty_map = [ $internal_url => [ 'nowprocket', 123, null, 'nowpcu' ], $external_url => 'not-an-array' ];
$dirty_raw = [ [ 'url' => $internal_url ], [ 'url' => $external_url ] ];
$dirty_out = ScannerAjax::stamp_bypass_suffixes( $dirty_raw, $dirty_map );
aias_assert( ( $dirty_out[0]['bypass_suffixes'] ?? null ) === [ 'nowprocket', 'nowpcu' ], 'non-string leaves filtered out' );
aias_assert( ! isset( $dirty_out[1]['bypass_suffixes'] ), 'non-array map value ignored (fail-closed)' );

// build side also filters non-string leaves and drops all-empty-after-filter entries.
$dirty_pages = [
    [ 'url' => 'https://x.test/a', 'bypass_suffixes' => [ 'ok', 42, false ] ],
    [ 'url' => 'https://x.test/b', 'bypass_suffixes' => [ 7, null ] ], // nothing survives → dropped
    [ 'url' => '', 'bypass_suffixes' => [ 'ok' ] ],                    // empty url → dropped
    'not-an-array',                                                    // non-array page → skipped
];
$dirty_built = ScannerAjax::build_bypass_map( $dirty_pages );
aias_assert( $dirty_built === [ 'https://x.test/a' => [ 'ok' ] ], 'build_bypass_map filters leaves, drops empty/urlless/non-array entries' );

// ---- absent/empty map → no stamping at all (transient expired / background rebuild) ----
$none = ScannerAjax::stamp_bypass_suffixes( $pages_raw, [] );
foreach ( $none as $r ) {
    aias_assert( ! isset( $r['bypass_suffixes'] ), 'empty map stamps nothing (fail-closed)' );
}

echo "bypass-suffix-map persist ok\n";
exit( 0 );

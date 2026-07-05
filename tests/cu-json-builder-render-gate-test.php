<?php
/**
 * Seam-proof: CuJsonBuilder render-health gate (FU-ABSENT-SAFE spec Rev 2 §2.2,
 * AC-A1 + AC-A3 PHP half). Standalone: php tests/cu-json-builder-render-gate-test.php
 * Prints "cu-json-builder render-gate ok" + exit 0; throws on first mismatch.
 */
define( 'ABSPATH', __DIR__ );

// wp_parse_url() is called by CuJsonBuilder::url_to_pattern() at runtime (not
// at class-load time), so a global fallback stub defined anywhere before the
// call site — including here, before the require — satisfies PHP's namespace
// function-fallback resolution (CUScanner\Scanner\wp_parse_url doesn't exist,
// so the unqualified call falls back to this global one). House-style stub,
// mirrors tests/CuJsonBuilderTest.php's WP_Mock equivalent.
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( string $url ) {
        return parse_url( $url );
    }
}

require __DIR__ . '/../includes/scanner/class-cu-json-builder.php';

use CUScanner\Scanner\CuJsonBuilder;

function aias_assert( $cond, string $msg ): void {
    if ( ! $cond ) { throw new RuntimeException( "FAIL: {$msg}" ); }
}

$mk_asset = function ( string $d, string $m, ?string $delayed = null ) {
    return [ 'handle' => 'h-' . md5( "{$d}{$m}" . (string) $delayed ), 'type' => 'style',
             'delayed_by' => $delayed,
             'desktop' => [ 'bucket' => $d ], 'mobile' => [ 'bucket' => $m ] ];
};
$mk_page = function ( array $assets ) {
    return [ 'status' => 'done', 'url' => 'https://example.test/', 'assets' => $assets ];
};
$builder = new CuJsonBuilder();
$flags   = [ 'combine_asymmetric_absent_enabled' => true, 'visual_diff_enabled' => true ];

// 1. DELAYED page: absent,absent emits NO rule; aggressive still emits; by_page S:0.
$delayed_page = $mk_page( [
    $mk_asset( 'absent', 'absent', 'data-pmdelayedstyle' ),
    $mk_asset( 'absent', 'absent' ),                    // unmarked absent — page-level gate covers it
    $mk_asset( 'aggressive', 'aggressive' ),
    $mk_asset( 'needed', 'needed', 'data-src' ),
] );
$out = $builder->build( [ $delayed_page ], $flags );
$safe_rules = array_filter( $out['rules'], fn( $r ) => 1 === $r['group_id'] );
$agg_rules  = array_filter( $out['rules'], fn( $r ) => 2 === $r['group_id'] );
aias_assert( 0 === count( $safe_rules ), 'delayed page must emit zero Safe rules' );
aias_assert( 1 === count( $agg_rules ), 'aggressive,aggressive still emits on a delayed page' );
aias_assert( 0 === $out['by_page'][0]['safe'], 'by_page safe tally must be 0' );
aias_assert( 3 === $out['by_page'][0]['needed'], 'gated absents land in the needed tally' );

// 2. CLEAN page (AC-A3): absent,absent → Safe, byte-identical full-map behavior.
$clean_page = $mk_page( [
    $mk_asset( 'absent', 'absent' ),
    $mk_asset( 'absent', 'aggressive' ),   // asymmetric — mobile agg rule
    $mk_asset( 'aggressive', 'absent' ),   // asymmetric — desktop agg rule
    $mk_asset( 'aggressive', 'needed' ),
    $mk_asset( 'needed', 'aggressive' ),
    $mk_asset( 'absent', 'needed' ),       // PHASE2A=false → no rule
    $mk_asset( 'needed', 'absent' ),       // PHASE2A=false → no rule
    $mk_asset( 'needed', 'needed' ),
] );
$out2 = $builder->build( [ $clean_page ], $flags );
$s = array_filter( $out2['rules'], fn( $r ) => 1 === $r['group_id'] );
$a = array_filter( $out2['rules'], fn( $r ) => 2 === $r['group_id'] );
aias_assert( 1 === count( $s ), 'clean page absent,absent still yields exactly 1 Safe rule' );
aias_assert( 4 === count( $a ), 'clean page aggressive cells yield exactly 4 rules' );
aias_assert( [ 'safe' => 1, 'aggressive' => 4, 'needed' => 3 ] === $out2['by_page'][0], 'clean-page tally byte-identical' );

// 3. ASYMMETRIC cells on a DELAYED page stay ungated (spec §2.2 rationale).
$asym_delayed = $mk_page( [ $mk_asset( 'absent', 'aggressive', 'data-pmdelayedstyle' ) ] );
$out3 = $builder->build( [ $asym_delayed ], $flags );
aias_assert( 1 === count( $out3['rules'] ) && 2 === $out3['rules'][0]['group_id'] && 'mobile' === $out3['rules'][0]['device_type'],
    'absent,aggressive on a delayed page still emits the mobile aggressive rule' );

// 4. Defensive predicate: delayed_by '' / non-string / missing → NOT delayed.
$edge_page = $mk_page( [
    [ 'handle' => 'e1', 'type' => 'style', 'delayed_by' => '', 'desktop' => [ 'bucket' => 'absent' ], 'mobile' => [ 'bucket' => 'absent' ] ],
    [ 'handle' => 'e2', 'type' => 'style', 'delayed_by' => 123, 'desktop' => [ 'bucket' => 'needed' ], 'mobile' => [ 'bucket' => 'needed' ] ],
    [ 'handle' => 'e3', 'type' => 'style', 'desktop' => [ 'bucket' => 'needed' ], 'mobile' => [ 'bucket' => 'needed' ] ],
] );
$out4 = $builder->build( [ $edge_page ], $flags );
aias_assert( 1 === count( array_filter( $out4['rules'], fn( $r ) => 1 === $r['group_id'] ) ),
    'page with only empty/non-string delayed_by is CLEAN — absent,absent still Safe' );

echo "cu-json-builder render-gate ok\n";

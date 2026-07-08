<?php
/**
 * Standalone: php tests/broken-banner-copy-map-test.php
 * Covers spec §3.1/§6.1 + AC-1: 12-key completeness, plain-text payload
 * (no HTML), rate-clause delegation, unknown-key fallback.
 */
define( 'ABSPATH', __DIR__ . '/' );
// WP stubs — i18n passthrough, escapers identity-ish, admin_url deterministic.
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function wp_kses_post( $s ) { return (string) $s; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function get_option( $k, $d = false ) { return $d; }
// class-broken-banner.php registers its AJAX hook unconditionally at file scope
// (add_action() at the bottom of the file) — stub it so require() below can
// load the real class without a WP runtime (brief's stub list omitted this).
function add_action( $tag, $cb, $priority = 10, $accepted_args = 1 ) { return true; }

require __DIR__ . '/../includes/class-broken-banner.php';

function aias_assert( $cond, $msg ) {
    if ( ! $cond ) { throw new RuntimeException( 'FAIL: ' . $msg ); }
}

$keys = [
    'tier2_cf_challenge', 'tier2_akamai_challenge', 'tier2_imperva_challenge',
    'tier2_waf_challenge', 'tier2_unknown_challenge', 'tier2_rocket_loader_stub',
    'tier2_small_body', 'tier1_zero_bytes', 'tier1_http_4xx', 'tier1_http_5xx',
    'tier1_http_rate_limit', 'tier1_transport_error',
];

// AC-1: every key yields a non-empty remediation with no embedded HTML.
foreach ( $keys as $k ) {
    $r = AIAS_Broken_Banner::reason_remediation( $k );
    aias_assert( is_string( $r ) && $r !== '', "remediation non-empty for $k" );
    aias_assert( strpos( $r, '<' ) === false, "remediation plain-text (no HTML) for $k" );
}
// Rate delegation: substantive, mentions rate-limiting behavior.
aias_assert( stripos( AIAS_Broken_Banner::reason_remediation( 'tier1_http_rate_limit' ), 'rate' ) !== false, 'rate clause delegated' );
// Unknown key falls back to its category clause (bot default), still non-empty plain text.
$unk = AIAS_Broken_Banner::reason_remediation( 'tier9_never_seen' );
aias_assert( $unk !== '' && strpos( $unk, '<' ) === false, 'unknown-key fallback plain text' );

// export_copy_map shape (spec §3.1).
$map = AIAS_Broken_Banner::export_copy_map();
foreach ( [ 'phrases', 'remediation', 'categories' ] as $sect ) {
    aias_assert( count( $map[ $sect ] ) === 12, "$sect has 12 keys" );
    foreach ( $keys as $k ) {
        aias_assert( array_key_exists( $k, $map[ $sect ] ), "$sect covers $k" );
        aias_assert( strpos( (string) $map[ $sect ][ $k ], '<' ) === false, "$sect/$k no HTML" );
    }
}
aias_assert( strpos( $map['settings_url'], 'cu-cloudflare-waf-bypass' ) !== false, 'settings_url anchors exemption section' );
$expected_link_keys = [ 'tier2_cf_challenge', 'tier2_rocket_loader_stub', 'tier1_http_rate_limit', 'tier2_waf_challenge', 'tier2_unknown_challenge' ];
aias_assert( $map['settings_link_keys'] === $expected_link_keys, 'settings_link_keys exact' );
// Phrases must be UNescaped source text (M1): the CF phrase contains no &quot;/&amp; artifacts.
aias_assert( strpos( $map['phrases']['tier2_cf_challenge'], '&' ) === false, 'phrases unescaped plain text' );

echo "broken-banner-copy-map-test ... ok\n";

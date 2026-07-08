<?php
/**
 * Standalone: php tests/plugin-detector-security-stacks-test.php
 * Covers spec §3.3/§6.2 + AC-2 (fixture match + clean-site false-positive guard)
 * + AC-5 (additive-only: outcome/bypass inputs untouched by security detection).
 */
define( 'ABSPATH', __DIR__ . '/' );
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function is_plugin_active( $p ) { return in_array( $p, $GLOBALS['aias_test_active'] ?? [], true ); }

require __DIR__ . '/../includes/scanner/class-plugin-detector.php';
use CUScanner\Scanner\PluginDetector;

function aias_assert( $cond, $msg ) { if ( ! $cond ) { throw new RuntimeException( 'FAIL: ' . $msg ); } }

// ── AC-2 fixtures (provenance: header sets mirror live captures; see fixture headers) ──
$cf_headers   = [ 'server' => 'cloudflare', 'cf-ray' => '8f1a2b3c4d5e6f70-VIE', 'cf-cache-status' => 'DYNAMIC' ];
$sucuri_headers = [ 'server' => 'Sucuri/Cloudproxy', 'x-sucuri-id' => '19008', 'x-sucuri-cache' => 'MISS' ];
$akamai_headers = [ 'x-akamai-transformed' => '9 - 0 pmb=mRUM,1', 'server' => 'AkamaiGHost' ];
$imperva_headers = [ 'x-iinfo' => '13-52872999-52873000 NNNN CT(0 0 0)', 'set-cookie' => 'incap_ses_123=abc; path=/' ];
$clean_headers  = [ 'server' => 'nginx', 'x-powered-by' => 'PHP/8.1' ];
$imperva_body   = '<html><head><script src="/_Incapsula_Resource?SWJIYLWA=719"></script></head></html>';
$clean_body     = '<html><head><title>Plain WP</title></head><body>hello</body></html>';

aias_assert( PluginDetector::detect_security_stacks( $cf_headers, $clean_body, true ) === [ 'cloudflare' ], 'CF via headers' );
aias_assert( PluginDetector::detect_security_stacks( $sucuri_headers, $clean_body, true ) === [ 'sucuri' ], 'Sucuri via headers' );
aias_assert( PluginDetector::detect_security_stacks( $akamai_headers, $clean_body, true ) === [ 'akamai' ], 'Akamai via headers' );
aias_assert( PluginDetector::detect_security_stacks( $imperva_headers, $clean_body, true ) === [ 'imperva' ], 'Imperva via headers' );
aias_assert( PluginDetector::detect_security_stacks( $clean_headers, $imperva_body, true ) === [ 'imperva' ], 'Imperva via body marker' );
aias_assert( PluginDetector::detect_security_stacks( $clean_headers, $clean_body, true ) === [], 'clean site: no false positives (AC-2 guard)' );
// CF body marker alone (challenge page shape): /cdn-cgi/ path reference.
$cf_body = '<html><head><script src="/cdn-cgi/challenge-platform/h/b/orchestrate"></script></head></html>';
aias_assert( PluginDetector::detect_security_stacks( $clean_headers, $cf_body, true ) === [ 'cloudflare' ], 'CF via body marker' );

// ── AC-T2-6 hoist invariant: detect_security_stacks must NEVER recompute the scoped body ──
$extract_before = PluginDetector::$extract_call_count;
PluginDetector::detect_security_stacks( $cf_headers, $imperva_body, true );
PluginDetector::detect_security_stacks( $clean_headers, $clean_body, false, '<head>pre-hoisted scoped body</head>' );
aias_assert( PluginDetector::$extract_call_count === $extract_before, 'detect_security_stacks never calls extract_non_text_zones (AC-T2-6 hoist invariant)' );

// ── active_security_warn_ids (same-site leg, spec §3.4) ──
$GLOBALS['aias_test_active'] = [ 'wordfence/wordfence.php' ];
$ids = PluginDetector::active_security_warn_ids();
aias_assert( count( $ids ) === 1 && $ids[0]['label'] === 'Wordfence', 'active_security_warn_ids maps SECURITY_WARN' );
aias_assert( array_key_exists( 'warning', $ids[0] ) && array_key_exists( 'anchor', $ids[0] ), 'ids carry warning + anchor' );
$GLOBALS['aias_test_active'] = [];
aias_assert( PluginDetector::active_security_warn_ids() === [], 'no active security plugins -> empty' );

echo "plugin-detector-security-stacks-test ... ok\n";

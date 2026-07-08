<?php
/**
 * Standalone: php tests/cdn-detector-cached-test.php
 * Covers spec §3.4 / d-review M2: detect_cached() = inbound + transient only, zero HTTP.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['aias_transients'] = [];
function get_transient( $k ) { return $GLOBALS['aias_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['aias_transients'][ $k ] = $v; return true; }
function wp_remote_get( $url, $args = [] ) { throw new RuntimeException( 'HTTP FORBIDDEN on detect_cached path (AC-6)' ); }
function wp_remote_retrieve_headers( $r ) { return []; }
function is_wp_error( $x ) { return false; }
function home_url( $p = '/' ) { return 'https://example.test' . $p; }

require __DIR__ . '/../includes/cdn/interface-adapter.php';
require __DIR__ . '/../includes/cdn/class-registry.php';
require __DIR__ . '/../includes/cdn/class-generic-adapter.php';
require __DIR__ . '/../includes/cdn/class-cloudflare-adapter.php';
require __DIR__ . '/../includes/cdn/class-detector.php';

use CUScanner\Cdn\Detector;
function aias_assert( $cond, $msg ) { if ( ! $cond ) { throw new RuntimeException( 'FAIL: ' . $msg ); } }

// 1. Cold cache, no inbound CDN headers -> null (and no HTTP throw).
$_SERVER = [ 'REQUEST_URI' => '/wp-admin/' ];
aias_assert( ( new Detector() )->detect_cached() === null, 'cold cache -> null, zero HTTP' );
// 2. Inbound CF header -> cloudflare (and transient warmed).
$_SERVER['HTTP_CF_RAY'] = '8f00-VIE';
aias_assert( ( new Detector() )->detect_cached() === 'cloudflare', 'inbound cf-ray -> cloudflare' );
unset( $_SERVER['HTTP_CF_RAY'] );
// 3. Transient hit (warmed by step 2) -> cloudflare without inbound headers.
aias_assert( ( new Detector() )->detect_cached() === 'cloudflare', 'transient hit -> cloudflare' );
// 4. Cached-miss sentinel '' -> null.
$GLOBALS['aias_transients']['cu_scanner_cdn_detected'] = '';
aias_assert( ( new Detector() )->detect_cached() === null, 'cached miss sentinel -> null' );
echo "cdn-detector-cached-test ... ok\n";

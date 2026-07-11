<?php
/**
 * Standalone: php tests/stack-display-names-test.php
 * FU-ANTIBLOCK-STACK-NAMES drift-guard: PluginDetector::stack_display_names() is the
 * SINGLE SOURCE for stack id -> display name. Pins (1) exact strings, (2) coverage of
 * every id either registry can surface (SECURITY_STACKS keys + Cdn adapter names),
 * (3) plain-text payload hygiene, (4) the dead per-row 'name' duplicate stays deleted.
 */
define( 'ABSPATH', __DIR__ . '/' );
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function is_plugin_active( $p ) { return false; }

require __DIR__ . '/../includes/scanner/class-plugin-detector.php';
require __DIR__ . '/../includes/cdn/interface-adapter.php';
require __DIR__ . '/../includes/cdn/class-registry.php';
require __DIR__ . '/../includes/cdn/class-generic-adapter.php';
require __DIR__ . '/../includes/cdn/class-cloudflare-adapter.php';
require __DIR__ . '/../includes/cdn/class-detector.php';

use CUScanner\Scanner\PluginDetector;
use CUScanner\Cdn\Detector;

function aias_assert( $cond, $msg ) { if ( ! $cond ) { throw new RuntimeException( 'FAIL: ' . $msg ); } }

$names = PluginDetector::stack_display_names();

// 1. Exact pin — catches accidental rename/drop (display copy is a UI contract).
$expected = [
	'cloudflare'         => 'Cloudflare',
	'sucuri'             => 'Sucuri',
	'akamai'             => 'Akamai',
	'imperva'            => 'Imperva/Incapsula',
	'bunnycdn'           => 'BunnyCDN',
	'fastly'             => 'Fastly',
	'wordfence'          => 'Wordfence',
	'siteground_antibot' => 'SiteGround Antibot',
];
aias_assert( $names === $expected, 'stack_display_names exact map (order + strings)' );

// 2. Registry coverage — every SECURITY_STACKS id has a display row.
$stacks = ( new ReflectionClass( PluginDetector::class ) )->getConstant( 'SECURITY_STACKS' );
foreach ( array_keys( $stacks ) as $id ) {
	aias_assert( isset( $names[ $id ] ), "SECURITY_STACKS id '$id' has a display name" );
}

// 3. Cdn adapter coverage — every adapter name() has a display row.
foreach ( Detector::default_registry()->all() as $a ) {
	$id = $a->name();
	aias_assert( isset( $names[ $id ] ), "Cdn adapter '$id' has a display name" );
}

// 4. Payload hygiene — non-empty plain text, no HTML (localized straight to JS).
foreach ( $names as $id => $label ) {
	aias_assert( is_string( $label ) && $label !== '' && strpos( $label, '<' ) === false, "label for '$id' non-empty plain text" );
}

// 5. SECURITY_STACKS rows carry no 'name' field (dead duplicate stays deleted).
foreach ( $stacks as $id => $entry ) {
	aias_assert( ! array_key_exists( 'name', $entry ), "SECURITY_STACKS '$id' has no duplicate name field" );
}

echo "stack-display-names-test ... ok\n";

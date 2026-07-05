<?php
/**
 * Seam-proof: PluginDetector probe-transient reliability (FU-ABSENT-SAFE Slice B1).
 * Standalone: php tests/plugin-detector-transient-tier-test.php
 * Prints "plugin-detector transient-tier ok" + exit 0; throws on first mismatch.
 *
 * Tests the two extracted static seams (positive_cache_ttl + build_cache_key) directly
 * rather than the full probe_target_stack() call chain — reaching the TTL decision through
 * probe_target_stack() would require stubbing wp_remote_get, is_wp_error, the
 * wp_remote_retrieve_* helpers, attach_resolution, debug_log_resolution, and
 * cu_scanner_debug_enabled, which is disproportionate
 * for testing a TTL tiering rule. Brief-authorized extraction — behavior-preserving; the
 * production call sites now call these same two methods (single source of truth), mirroring
 * this file's existing attach_resolution / __test_attach_resolution seam pattern.
 */
define( 'ABSPATH', __DIR__ );
if ( ! defined( 'DAY_IN_SECONDS' ) )    { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

require __DIR__ . '/../includes/scanner/class-plugin-detector.php';

use CUScanner\Scanner\PluginDetector;

function aias_assert( $cond, string $msg ): void {
    if ( ! $cond ) { throw new RuntimeException( "FAIL: {$msg}" ); }
}

$mk = function ( string $outcome, array $suffixes ) {
    return [ 'outcome' => $outcome, 'bypass_suffixes' => $suffixes ];
};

// ---- TTL tiering matrix ----

// 1. class_bc_only + empty suffixes -> 15 min (the fix; was 24h before this task).
aias_assert(
    15 * MINUTE_IN_SECONDS === PluginDetector::__test_positive_cache_ttl( $mk( 'class_bc_only', [] ) ),
    'class_bc_only with empty bypass_suffixes must get the 15-min TTL, not 24h'
);

// 2. class_a_clean + non-empty suffixes -> 24h.
aias_assert(
    DAY_IN_SECONDS === PluginDetector::__test_positive_cache_ttl( $mk( 'class_a_clean', [ 'perfmattersoff' ] ) ),
    'class_a_clean with a real bypass suffix must get the 24h TTL'
);

// 3. hybrid_a_plus_bc + non-empty suffixes -> 24h.
aias_assert(
    DAY_IN_SECONDS === PluginDetector::__test_positive_cache_ttl( $mk( 'hybrid_a_plus_bc', [ 'ao_noptimize=1' ] ) ),
    'hybrid_a_plus_bc with a real bypass suffix must get the 24h TTL'
);

// 3b. hybrid_a_plus_bc + empty suffixes -> 15 min.
aias_assert(
    15 * MINUTE_IN_SECONDS === PluginDetector::__test_positive_cache_ttl( $mk( 'hybrid_a_plus_bc', [] ) ),
    'hybrid_a_plus_bc with empty bypass_suffixes must get the 15-min TTL'
);

// 4. probe_failed -> 15 min (unchanged).
aias_assert(
    15 * MINUTE_IN_SECONDS === PluginDetector::__test_positive_cache_ttl( $mk( 'probe_failed', [] ) ),
    'probe_failed must stay on the 15-min TTL'
);

// 4b. no_clue / non_wordpress (unknown-to-allowlist outcomes) also stay short — regression
// guard on the "positive set is an allowlist" behavior carried over from the original code.
aias_assert(
    15 * MINUTE_IN_SECONDS === PluginDetector::__test_positive_cache_ttl( $mk( 'no_clue', [] ) ),
    'no_clue must stay on the 15-min TTL'
);
aias_assert(
    15 * MINUTE_IN_SECONDS === PluginDetector::__test_positive_cache_ttl( $mk( 'non_wordpress', [ 'should-not-matter' ] ) ),
    'non_wordpress must stay on the 15-min TTL even if a suffixes array is somehow present'
);

// 5. Defensive: missing bypass_suffixes key entirely must fail closed to the short TTL
// (must not fatal on a malformed/legacy-cached result, must not silently grant 24h).
aias_assert(
    15 * MINUTE_IN_SECONDS === PluginDetector::__test_positive_cache_ttl( [ 'outcome' => 'class_a_clean' ] ),
    'missing bypass_suffixes key must fail closed to the 15-min TTL'
);

// ---- Cache-key schema-version salting ----

$version = ( new ReflectionClass( PluginDetector::class ) )->getConstant( 'SIGNATURE_SCHEMA_VERSION' );
aias_assert( is_string( $version ) && $version !== '', 'SIGNATURE_SCHEMA_VERSION must be a non-empty string const' );

$key = PluginDetector::__test_build_cache_key( 'https', 'example.test', '443' );
aias_assert(
    strpos( $key, 'cu_scanner_target_stack_v' . $version . '_' ) === 0,
    'cache key must be prefixed with cu_scanner_target_stack_v<SIGNATURE_SCHEMA_VERSION>_ -- key was: ' . $key
);
aias_assert(
    $key === 'cu_scanner_target_stack_v' . $version . '_' . md5( 'https://example.test:443' ),
    // Reading the version through Reflection (not hardcoding '4' here) means this assertion
    // automatically re-verifies against whatever value the const holds on a future bump --
    // i.e. it proves the key changes whenever SIGNATURE_SCHEMA_VERSION changes.
    'cache key must equal the const-salted md5 formula exactly'
);

echo "plugin-detector transient-tier ok\n";

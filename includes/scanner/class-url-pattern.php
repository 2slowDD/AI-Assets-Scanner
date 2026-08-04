<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * The ONE URL→CU-url_pattern normalizer.
 *
 * Was duplicated as a private method on CuJsonBuilder AND RatchetMerger, with a test
 * asserting the two stayed byte-identical — and production code reaching the second
 * copy through a __test_ seam. All three now delegate here.
 *
 * Format: scheme://host/path — lowercased scheme+host, query and fragment stripped,
 * trailing slash only on root. Matches PatternMatcher::normalize_url().
 *
 * ⚠️ Stripping the query means a URL and its ?ver= variant collapse to ONE pattern.
 * Callers that aggregate per pattern MUST handle several scanned pages mapping to one
 * pattern (see ScannerAjax's group aggregation).
 */
class UrlPattern {

    public static function from_url( string $url ): string {
        $parsed = wp_parse_url( $url );
        $scheme = strtolower( $parsed['scheme'] ?? 'https' );
        $host   = strtolower( $parsed['host']   ?? '' );
        $path   = $parsed['path'] ?? '/';
        if ( '/' !== $path ) {
            $path = rtrim( $path, '/' );
        }
        return $scheme . '://' . $host . $path;
    }
}

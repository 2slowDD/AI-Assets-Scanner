<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shared event idempotency hash. MIRRORED byte-identically in
 * AI-Assets-Scanner/includes/lib-fields-hash.php (Task 2.6).
 * Any change here MUST be applied to both copies.
 */

if ( ! function_exists( 'cu_fields_hash' ) ) {
    // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- 'cu_fields_hash*' is the long-standing internal prefix; this file is byte-mirrored across AI-Assets-Scanner, wpservice-saas, and the Railway worker so the same idempotency hash is computed in all three components. Renaming would force a synchronized cross-repo break.
    function cu_fields_hash( array $fields ): string {
        $sorted = cu_fields_hash_deep_ksort( $fields );
        $json   = json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return substr( hash( 'sha256', $json ), 0, 16 );
    }

    function cu_fields_hash_deep_ksort( array $arr ): array {
        ksort( $arr );
        foreach ( $arr as $k => $v ) {
            if ( is_array( $v ) ) {
                $arr[ $k ] = cu_fields_hash_deep_ksort( $v );
            }
        }
        return $arr;
    }
    // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}

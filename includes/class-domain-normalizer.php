<?php
namespace CUScanner;

defined( 'ABSPATH' ) || exit;

class DomainNormalizer {

    public static function normalize_url( string $url ): string {
        $url = trim( $url );
        if ( '' === $url ) {
            return '';
        }

        $host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : null;
        if ( ! is_string( $host ) || '' === $host ) {
            $host = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test/runtime fallback when wp_parse_url is unavailable or mocked.
        }

        if ( ! is_string( $host ) || '' === $host ) {
            $host = $url;
        }

        return self::normalize_host( $host );
    }

    public static function normalize_host( string $host ): string {
        $host = strtolower( trim( $host ) );
        $host = preg_replace( '/:\d+$/', '', $host ) ?: '';
        $host = rtrim( $host, '.' );
        if ( str_starts_with( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }
        return preg_replace( '/[^a-z0-9.\-]/', '', $host ) ?: '';
    }
}


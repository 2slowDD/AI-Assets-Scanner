<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

class PageDiscovery {
    private array $urls           = [];
    private array $excluded_urls  = [];
    private array $excluded_types = [];

    public function set_manual_urls( array $urls ): void {
        $this->urls = $urls;
    }

    public function set_excluded_urls( array $urls ): void {
        $this->excluded_urls = $urls;
    }

    public function set_excluded_post_types( array $types ): void {
        $this->excluded_types = $types;
    }

    /** Fetch and parse a sitemap XML. Returns [] on any failure. */
    public function discover_from_sitemap( string $sitemap_url ): array {
        $response = wp_remote_get( $sitemap_url, [ 'timeout' => 15 ] );
        if ( is_wp_error( $response ) ) return [];
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return [];
        $xml = wp_remote_retrieve_body( $response );
        if ( ! $xml ) return [];
        $doc = @simplexml_load_string( $xml );
        if ( ! $doc ) return [];
        // Handle sitemap index (nested sitemaps)
        if ( isset( $doc->sitemap ) ) {
            $urls = [];
            foreach ( $doc->sitemap as $entry ) {
                $urls = array_merge( $urls, $this->discover_from_sitemap( (string) $entry->loc ) );
            }
            return $urls;
        }
        $urls = [];
        foreach ( $doc->url as $entry ) {
            $urls[] = (string) $entry->loc;
        }
        return $urls;
    }

    /**
     * WP_Query fallback: all published pages, posts, and non-excluded CPTs.
     * Returns array of permalink strings.
     */
    public function discover_from_wpquery(): array {
        $post_types = array_diff(
            get_post_types( [ 'public' => true ] ),
            array_merge( [ 'attachment' ], $this->excluded_types )
        );
        $query = new \WP_Query( [
            'post_type'      => array_values( $post_types ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );
        $urls = [];
        foreach ( $query->posts as $id ) {
            $urls[] = get_permalink( $id );
        }
        return array_filter( $urls );
    }

    public function get_urls(): array {
        // array_unique() de-dupes URLs that a sitemap can list more than once
        // (e.g. the WooCommerce shop page registered in multiple sitemap sections),
        // which otherwise both display in the page list and double-count credits.
        return array_values( array_unique( array_diff( $this->urls, $this->excluded_urls ) ) );
    }

    /**
     * FU-AAS-DISCOVER-HOMEPAGE-FIRST (1.8.5) — the ONE normaliser both the discover_pages group walk
     * and home_first() use, so "is this URL the homepage" and "which post-type group is this URL"
     * can never disagree on scheme or trailing slash. Sitemaps may list http:// or omit the slash;
     * get_permalink() / get_home_url() output is https + slashed.
     */
    public static function normalise_url( string $u ): string {
        return trailingslashit( set_url_scheme( $u, 'https' ) );
    }

    /**
     * FU-AAS-DISCOVER-HOMEPAGE-FIRST (1.8.5) — move the homepage to index 0 (operator rulings B1/B2):
     * the first URL whose normalised form equals the normalised $home_url; if none, the shortest URL by
     * string length (ties → the earliest). Order of the rest is preserved, keys re-indexed. Pure.
     */
    public static function home_first( array $urls, string $home_url ): array {
        if ( $urls === [] ) {
            return $urls;
        }
        $urls  = array_values( $urls );
        $home  = self::normalise_url( $home_url );
        $index = null;
        foreach ( $urls as $i => $u ) {
            if ( self::normalise_url( (string) $u ) === $home ) {
                $index = $i;
                break;
            }
        }
        if ( $index === null ) {
            $shortest = PHP_INT_MAX;
            foreach ( $urls as $i => $u ) {
                $len = strlen( (string) $u );
                if ( $len < $shortest ) {
                    $shortest = $len;
                    $index    = $i;
                }
            }
        }
        if ( $index === null || $index === 0 ) {
            return $urls;
        }
        $picked = $urls[ $index ];
        unset( $urls[ $index ] );
        return array_values( array_merge( [ $picked ], $urls ) );
    }

    public function get_credit_cost(): int {
        return count( $this->get_urls() );
    }
}

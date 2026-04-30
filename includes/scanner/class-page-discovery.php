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
        return array_values( array_diff( $this->urls, $this->excluded_urls ) );
    }

    public function get_credit_cost(): int {
        return count( $this->get_urls() );
    }
}

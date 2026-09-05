<?php
// tests/PageDiscoveryTest.php
namespace CUScanner\Tests;

use CUScanner\Scanner\PageDiscovery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class PageDiscoveryTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_manual_urls_returned_as_is(): void {
        $discovery = new PageDiscovery();
        $discovery->set_manual_urls( [ 'https://site.com/about/', 'https://site.com/contact/' ] );
        $this->assertSame(
            [ 'https://site.com/about/', 'https://site.com/contact/' ],
            $discovery->get_urls()
        );
    }

    public function test_exclusions_filter_manual_urls(): void {
        $discovery = new PageDiscovery();
        $discovery->set_manual_urls( [ 'https://site.com/about/', 'https://site.com/admin/' ] );
        $discovery->set_excluded_urls( [ 'https://site.com/admin/' ] );
        $urls = $discovery->get_urls();
        $this->assertNotContains( 'https://site.com/admin/', $urls );
        $this->assertContains( 'https://site.com/about/', $urls );
    }

    public function test_parse_sitemap_extracts_urls(): void {
        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '<?xml version="1.0"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://site.com/about/</loc></url>
                <url><loc>https://site.com/contact/</loc></url>
            </urlset>' );

        $discovery = new PageDiscovery();
        $urls = $discovery->discover_from_sitemap( 'https://site.com/sitemap.xml' );
        $this->assertCount( 2, $urls );
        $this->assertContains( 'https://site.com/about/', $urls );
    }

    public function test_parse_sitemap_returns_empty_on_failure(): void {
        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( true );

        $discovery = new PageDiscovery();
        $urls = $discovery->discover_from_sitemap( 'https://site.com/sitemap.xml' );
        $this->assertSame( [], $urls );
    }

    public function test_get_credit_cost_equals_url_count(): void {
        $discovery = new PageDiscovery();
        $discovery->set_manual_urls( [ 'https://a.com/', 'https://b.com/', 'https://c.com/' ] );
        $this->assertSame( 3, $discovery->get_credit_cost() );
    }

    public function test_get_urls_dedupes_duplicate_urls(): void {
        // A sitemap can list the same URL twice (e.g. the WooCommerce shop page
        // registered in multiple sitemap sections). get_urls() must collapse them.
        $discovery = new PageDiscovery();
        $discovery->set_manual_urls( [
            'https://site.com/shop/',
            'https://site.com/about/',
            'https://site.com/shop/',
        ] );
        $this->assertSame(
            [ 'https://site.com/shop/', 'https://site.com/about/' ],
            $discovery->get_urls()
        );
    }

    public function test_get_credit_cost_does_not_double_count_duplicates(): void {
        $discovery = new PageDiscovery();
        $discovery->set_manual_urls( [
            'https://site.com/shop/',
            'https://site.com/shop/',
            'https://site.com/about/',
        ] );
        $this->assertSame( 2, $discovery->get_credit_cost() );
    }

    private function mock_normalisers(): void {
        WP_Mock::userFunction( 'set_url_scheme' )->andReturnUsing(
            fn( string $url, string $scheme = 'https' ): string => preg_replace( '#^https?://#i', $scheme . '://', $url )
        );
        WP_Mock::userFunction( 'trailingslashit' )->andReturnUsing(
            fn( string $s ): string => rtrim( $s, '/' ) . '/'
        );
    }

    public function test_normalise_url_forces_https_and_a_trailing_slash(): void {
        $this->mock_normalisers();
        $this->assertSame( 'https://site.test/a/', PageDiscovery::normalise_url( 'http://site.test/a' ) );
        $this->assertSame( 'https://site.test/', PageDiscovery::normalise_url( 'https://site.test' ) );
        $this->assertSame( 'https://site.test/a/', PageDiscovery::normalise_url( 'https://site.test/a/' ) );
    }

    public function test_home_first_moves_the_home_url_to_index_zero_preserving_the_rest(): void {
        $this->mock_normalisers();
        $in = [ 'https://site.test/a/', 'https://site.test/b/', 'https://site.test/c/', 'https://site.test/', 'https://site.test/d/' ];
        $this->assertSame(
            [ 'https://site.test/', 'https://site.test/a/', 'https://site.test/b/', 'https://site.test/c/', 'https://site.test/d/' ],
            PageDiscovery::home_first( $in, 'https://site.test/' )
        );
    }

    public function test_home_first_matches_after_normalisation_scheme_and_slash(): void {
        $this->mock_normalisers();
        $in = [ 'https://site.test/a/', 'http://site.test' ];
        $this->assertSame( [ 'http://site.test', 'https://site.test/a/' ], PageDiscovery::home_first( $in, 'https://site.test/' ) );
    }

    public function test_home_first_falls_back_to_the_shortest_url_when_home_is_absent(): void {
        $this->mock_normalisers();
        $in = [ 'https://site.test/about-us/', 'https://site.test/blog/', 'https://site.test/a/', 'https://site.test/contact/' ];
        $this->assertSame(
            [ 'https://site.test/a/', 'https://site.test/about-us/', 'https://site.test/blog/', 'https://site.test/contact/' ],
            PageDiscovery::home_first( $in, 'https://site.test/' )
        );
    }

    public function test_home_first_shortest_tie_goes_to_the_earlier_url(): void {
        $this->mock_normalisers();
        $in = [ 'https://site.test/zz/', 'https://site.test/b/', 'https://site.test/a/' ];
        $this->assertSame( [ 'https://site.test/b/', 'https://site.test/zz/', 'https://site.test/a/' ], PageDiscovery::home_first( $in, 'https://site.test/' ) );
    }

    public function test_home_first_is_identity_when_home_is_already_first(): void {
        $this->mock_normalisers();
        $in = [ 'https://site.test/', 'https://site.test/a/' ];
        $this->assertSame( $in, PageDiscovery::home_first( $in, 'https://site.test/' ) );
    }

    public function test_home_first_on_empty_input_returns_empty(): void {
        $this->mock_normalisers();
        $this->assertSame( [], PageDiscovery::home_first( [], 'https://site.test/' ) );
    }

    public function test_home_first_reindexes_keys(): void {
        $this->mock_normalisers();
        $out = PageDiscovery::home_first( [ 3 => 'https://site.test/a/', 7 => 'https://site.test/' ], 'https://site.test/' );
        $this->assertSame( [ 0, 1 ], array_keys( $out ) );
    }
}

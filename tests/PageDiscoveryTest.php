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
}

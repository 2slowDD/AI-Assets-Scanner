<?php
namespace CUScanner\Tests;

use PHPUnit\Framework\TestCase;
use WP_Mock;
use CUScanner\Scanner\UrlPattern;

/**
 * AC-13 — characterization of the shared URL→CU-pattern normalizer.
 *
 * This corpus is inherited from RatchetMergerTest's former drift guard. Its value is
 * that merge() key alignment and CU rule matching both depend on these exact outputs:
 * scheme://host/path, lowercased, query stripped, trailing slash only on root.
 *
 * ⚠️ Every expected value below was executed against the PRE-EXTRACTION body of
 * CuJsonBuilder::url_to_pattern() and matched 11/11. This freezes what IS. If one of
 * these ever fails, the correct response is to revert the production change — not to
 * update the expectation.
 */
class UrlPatternTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		// wp_parse_url is not defined by the test bootstrap; every sibling suite stubs it
		// as a passthrough to native parse_url (see CuJsonBuilderTest:13).
		WP_Mock::userFunction( 'wp_parse_url' )
			->andReturnUsing( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function corpus(): array {
		return [
			'root'            => [ 'https://s.com/',            'https://s.com/' ],
			'root-no-slash'   => [ 'https://s.com',             'https://s.com/' ],
			'simple-path'     => [ 'https://s.com/p',           'https://s.com/p' ],
			'trailing-slash'  => [ 'https://s.com/p/',          'https://s.com/p' ],
			'nested'          => [ 'https://s.com/a/b/c/',      'https://s.com/a/b/c' ],
			'query-string'    => [ 'https://s.com/p?ver=2',     'https://s.com/p' ],
			'bypass-suffix'   => [ 'https://s.com/p?nowpcu',    'https://s.com/p' ],
			'upper-host'      => [ 'https://S.COM/p',           'https://s.com/p' ],
			'upper-scheme'    => [ 'HTTPS://s.com/p',           'https://s.com/p' ],
			'http-preserved'  => [ 'http://s.com/p',            'http://s.com/p' ],
			'no-scheme'       => [ 's.com/p',                   'https://s.com/p' ],
		];
	}

	/** @dataProvider corpus */
	public function test_from_url_matches_the_frozen_corpus( string $url, string $expected ): void {
		$this->assertSame( $expected, UrlPattern::from_url( $url ) );
	}

	/**
	 * The property the old drift guard protected: a URL and its query-string variant
	 * collapse to ONE pattern. Task 3's group aggregation depends on this being true.
	 */
	public function test_query_variants_collapse_to_one_pattern(): void {
		$this->assertSame(
			UrlPattern::from_url( 'https://s.com/p' ),
			UrlPattern::from_url( 'https://s.com/p?ver=2' )
		);
	}

	/**
	 * The class must be reachable through the PRODUCTION autoloader map, not only the
	 * test bootstrap's. Both are hand-maintained arrays in separate files, so registering
	 * one and forgetting the other leaves every test green while a live site fatals on
	 * the first result build. Asserting on the map as DATA (an executed require of the
	 * returned array) rather than on the file's source text.
	 */
	public function test_production_autoloader_maps_the_class(): void {
		$map = require dirname( __DIR__ ) . '/includes/autoload-map.php';

		$this->assertArrayHasKey( UrlPattern::class, $map, 'production autoload map is missing the class' );
		$this->assertFileExists(
			dirname( __DIR__ ) . '/' . $map[ UrlPattern::class ],
			'production autoload map points at a file that does not exist'
		);
	}
}

<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\CU_DepGraph_Island;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Plan B Task 2 — per-page declared-dependency island (AC-1 / AC-9 / AC-15).
 *
 * Island contract (SHARED with the worker parser — do NOT diverge):
 *   <script type="application/json" id="cu-dep-graph">{"v":1,"dropped":N,"truncated":0,
 *     "scripts":{"<handle>":{"d":["dep",...],"a":0}}}</script>
 */
class DepGraphIslandTest extends TestCase {
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$_GET = [];
		CU_DepGraph_Island::for_testing_reset();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		$_GET = [];
		CU_DepGraph_Island::for_testing_reset();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a WP_Scripts-shaped stub.
	 *
	 * @param array<string, array{deps?: array<int, string>, src?: string|false}> $spec
	 */
	private function mock_wp_scripts( array $spec ): void {
		$registry = [];
		foreach ( $spec as $handle => $conf ) {
			$dep              = new \stdClass();
			$dep->deps        = $conf['deps'] ?? [];
			$dep->src         = $conf['src'] ?? false;
			$registry[ $handle ] = $dep;
		}

		$wp_scripts             = new \stdClass();
		$wp_scripts->registered = $registry;

		WP_Mock::userFunction( 'wp_scripts' )->andReturn( $wp_scripts );
	}

	/** Real encoder behind the WP wrapper. */
	private function mock_real_json_encode(): void {
		WP_Mock::userFunction( 'wp_json_encode' )
			->andReturnUsing( static fn( $data, $flags = 0, $depth = 512 ) => json_encode( $data, $flags, $depth ) );
	}

	/** Drive the gated registration path with a valid token + the marker present. */
	private function arm(): void {
		$_GET['cu_dep_graph'] = '1';
		WP_Mock::userFunction( 'nocache_headers' );
		WP_Mock::expectActionAdded( 'wp_footer', [ CU_DepGraph_Island::class, 'emit' ], PHP_INT_MAX );
		CU_DepGraph_Island::maybe_register( 'tok-good' );
	}

	/** Run the footer hook and capture what it echoed. */
	private function capture_emit(): string {
		ob_start();
		CU_DepGraph_Island::emit();
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// AC-1 — island present on a validated token + marker request
	// -------------------------------------------------------------------------

	public function test_emits_island_on_token_and_marker(): void {
		$this->mock_wp_scripts( [
			'jquery-core' => [ 'deps' => [], 'src' => '/wp-includes/js/jquery/jquery.js' ],
			'theme-main'  => [ 'deps' => [ 'jquery-core' ], 'src' => '/themes/x/main.js' ],
			'inline-only' => [ 'deps' => [ 'theme-main' ], 'src' => false ],
		] );
		$this->mock_real_json_encode();

		$this->arm();
		$out = $this->capture_emit();

		$this->assertStringContainsString( '<script type="application/json" id="cu-dep-graph">', $out );
		$this->assertStringEndsWith( '</script>', $out );
		$this->assertStringContainsString( '"jquery-core"', $out );

		$payload = $this->decode_island( $out );
		$this->assertSame( 1, $payload['v'] );
		$this->assertSame( 0, $payload['dropped'] );
		$this->assertSame( 0, $payload['truncated'] );
		$this->assertSame( [], $payload['scripts']['jquery-core']['d'] );
		$this->assertSame( 0, $payload['scripts']['jquery-core']['a'] );
		$this->assertSame( [ 'jquery-core' ], $payload['scripts']['theme-main']['d'] );
		$this->assertSame( 0, $payload['scripts']['theme-main']['a'] );
		// a:1 when src is not a non-empty string.
		$this->assertSame( 1, $payload['scripts']['inline-only']['a'] );
	}

	// -------------------------------------------------------------------------
	// F-SEC — never on a public view
	// -------------------------------------------------------------------------

	public function test_no_island_without_token(): void {
		$_GET['cu_dep_graph'] = '1'; // marker present, but no validated token
		$this->mock_wp_scripts( [ 'jquery-core' => [ 'src' => '/j.js' ] ] );
		$this->mock_real_json_encode();
		WP_Mock::userFunction( 'nocache_headers' )->never();

		CU_DepGraph_Island::maybe_register( '' );

		$this->assertSame( '', $this->capture_emit() );
	}

	public function test_no_island_without_marker(): void {
		// Valid token, but the scanner did not ask for the graph on this request.
		$this->mock_wp_scripts( [ 'jquery-core' => [ 'src' => '/j.js' ] ] );
		$this->mock_real_json_encode();
		WP_Mock::userFunction( 'nocache_headers' )->never();

		CU_DepGraph_Island::maybe_register( 'tok-good' );

		$this->assertSame( '', $this->capture_emit() );
	}

	// -------------------------------------------------------------------------
	// AC-15 — charset allowlist; violators dropped and counted
	// -------------------------------------------------------------------------

	public function test_script_tag_handle_dropped(): void {
		$this->mock_wp_scripts( [
			'</script><script>alert(1)</script>' => [ 'deps' => [], 'src' => '/evil.js' ],
			'good-handle'                        => [ 'deps' => [ 'bad dep', 'ok.dep:1-2' ], 'src' => '/ok.js' ],
		] );
		$this->mock_real_json_encode();

		$this->arm();
		$out = $this->capture_emit();

		$this->assertStringNotContainsString( 'alert(1)', $out );
		// Only the island's own opening tag — no injected one.
		$this->assertSame( 1, substr_count( $out, '<script' ) );

		$payload = $this->decode_island( $out );
		$this->assertGreaterThanOrEqual( 1, $payload['dropped'] );
		$this->assertSame( 2, $payload['dropped'] ); // 1 bad handle + 1 bad dep
		$this->assertArrayNotHasKey( '</script><script>alert(1)</script>', $payload['scripts'] );
		$this->assertSame( [ 'ok.dep:1-2' ], $payload['scripts']['good-handle']['d'] );
	}

	// -------------------------------------------------------------------------
	// Encoder failure (invalid UTF-8) -> emit nothing
	// -------------------------------------------------------------------------

	public function test_invalid_utf8_emits_nothing(): void {
		$this->mock_wp_scripts( [ 'jquery-core' => [ 'src' => '/j.js' ] ] );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturn( false );

		$this->arm();

		$this->assertSame( '', $this->capture_emit() );
	}

	// -------------------------------------------------------------------------
	// AC-9 — 128 KB cap
	// -------------------------------------------------------------------------

	public function test_over_cap_truncates(): void {
		$spec = [];
		for ( $i = 0; $i < 1200; $i++ ) {
			$handle          = str_pad( (string) $i, 128, 'a', STR_PAD_LEFT );
			$spec[ $handle ] = [ 'deps' => [], 'src' => '/s.js' ];
		}
		$this->mock_wp_scripts( $spec );
		$this->mock_real_json_encode();

		$this->arm();
		$out = $this->capture_emit();

		$this->assertStringContainsString( '"truncated":1', $out );
		$this->assertStringNotContainsString( '"scripts"', $out );
		$this->assertStringNotContainsString( '"dropped"', $out );
		$this->assertSame(
			'<script type="application/json" id="cu-dep-graph">{"v":1,"truncated":1}</script>',
			$out
		);
	}

	// -------------------------------------------------------------------------
	// Exactly ONE island per page
	// -------------------------------------------------------------------------

	public function test_emits_exactly_one_island(): void {
		$this->mock_wp_scripts( [ 'jquery-core' => [ 'src' => '/j.js' ] ] );
		$this->mock_real_json_encode();

		$this->arm();
		// A second registration attempt must not arm a second hook.
		CU_DepGraph_Island::maybe_register( 'tok-good' );

		// Even if the footer hook somehow runs twice, only one island lands.
		ob_start();
		CU_DepGraph_Island::emit();
		CU_DepGraph_Island::emit();
		$out = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $out, 'id="cu-dep-graph"' ) );
		$this->assertSame( 1, substr_count( $out, '</script>' ) );
	}

	// -------------------------------------------------------------------------

	/** @return array<string, mixed> */
	private function decode_island( string $html ): array {
		$open = '<script type="application/json" id="cu-dep-graph">';
		$this->assertStringStartsWith( $open, $html );
		$json    = substr( $html, strlen( $open ), -strlen( '</script>' ) );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded, 'Island payload must be valid JSON: ' . $json );
		return $decoded;
	}
}

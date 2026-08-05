<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * AC-8 (refund failure is non-fatal) · AC-16 (the second update_status preserves status).
 *
 * ⚠️ AC-16 is driven through the REAL do_build_result() → REAL ScanHistory::update_status(),
 * with only wp_remote_* and the options store stubbed. A test that calls a ScanHistory
 * double twice by hand and asserts the second call carried the status it was itself given
 * is tautological: it cannot fail for the production mistake it exists to catch (the second
 * call omitting $hist_status). Mutation-proven below.
 *
 * P17: Code Unloader is made present the way production sees it — by declaring the real
 * class name RulePusher resolves by DEFAULT. Nothing is injected into do_build_result().
 */
class ResultTruthRefundClaimTest extends TestCase {

	/** Captured [ option_name => value ] from every update_option() call, in order. */
	private array $option_writes = [];
	/** Captured refund POST bodies. */
	private array $refund_posts = [];

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->option_writes = [];
		$this->refund_posts  = [];
		FakeCuRepo::reset();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * One page whose single asset yields one aggressive rule, so by_page has a tally and
	 * the page is refundable once CU already holds that rule.
	 */
	private function page( string $url = 'https://s.com/p' ): array {
		return [
			'url'    => $url,
			'status' => 'done',
			'assets' => [ [
				'handle'  => 'h-one',
				'type'    => 'style',
				'desktop' => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => 'aggressive' ],
				'mobile'  => [ 'loaded' => true, 'coverage' => 0.0, 'bucket' => 'aggressive' ],
			] ],
		];
	}

	/**
	 * Drives the real do_build_result(). $refund_response is what the SaaS returns from
	 * POST /credits/refund-duplicates; null makes the call throw (non-2xx).
	 *
	 * ⚠️ $omit_cu_bypass DEFAULTS TRUE — "the ?nowpcu suffix was omitted", i.e. the scan ran with
	 * Code Unloader's rules LIVE. Operator ruling 2026-08-05 scopes the entire result-truth
	 * apparatus (dedupe → split counts → netting → credit-back) to that mode: with the suffix
	 * APPLIED, CU is off for the scan, the scan is a fresh full measurement, and every rule it
	 * produces is billed. So the default here is the only mode in which this class's subject
	 * exists. The suffix-applied case has its own explicit tests below.
	 */
	private function run_build( bool $cu_holds_the_rule, ?array $refund_response, bool $cu_active = true, bool $omit_cu_bypass = true ): array {
		WP_Mock::userFunction( 'wp_parse_url' )
			->andReturnUsing( fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		WP_Mock::userFunction( '__' )->andReturnUsing( fn( $t, $d = null ) => $t );
		WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://s.com' );

		// Railway boundary.
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [] );
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing(
			fn( $r ) => is_array( $r ) && isset( $r['__refund'] ) ? (int) $r['__code'] : 200
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing(
			function ( $r ) use ( $refund_response ) {
				if ( is_array( $r ) && isset( $r['__refund'] ) ) {
					return json_encode( $refund_response ?? [ 'message' => 'Not Found' ] );
				}
				return json_encode( [
					'status'    => 'complete',
					'total'     => 1,
					'completed' => 1,
					'pages'     => [ $this->page() ],
					'flags'     => [],
				] );
			}
		);

		// SaaS boundary — the refund POST.
		WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing(
			function ( $url, $args ) use ( $refund_response ) {
				$this->refund_posts[] = [ 'url' => $url, 'args' => $args ];
				return [ '__refund' => true, '__code' => null === $refund_response ? 404 : 200 ];
			}
		);

		// Options store: seed the history row do_build_result will update.
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( $k, $default = false ) use ( $omit_cu_bypass ) {
				if ( 'cu_scanner_railway_url' === $k ) { return 'https://cu-scanner-railway-production.up.railway.app'; }
				if ( 'cu_scanner_api_key' === $k )     { return 'api-key-123'; }
				// P17: seed the REAL option Settings::get_omit_cu_bypass() reads. Nothing is
				// passed into do_build_result() — the production config lookup executes, so a
				// wrong option name or a dropped read makes the cu_rules_active tests go red.
				if ( 'cu_scanner_omit_cu_bypass' === $k ) { return $omit_cu_bypass ? '1' : ''; }
				if ( 'cu_scanner_history' === $k ) {
					foreach ( array_reverse( $this->option_writes ) as $w ) {
						if ( 'cu_scanner_history' === $w[0] ) { return $w[1]; }
					}
					return [ [
						'job_id' => 'job-xyz', 'domain' => 's.com', 'page_count' => 1,
						'status' => 'queued', 'created_at' => '2026-08-04T00:00:00+00:00',
						'credits_used' => 0, 'safe_count' => 0, 'aggressive_count' => 0,
					] ];
				}
				return $default;
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $k, $v, $autoload = null ) { $this->option_writes[] = [ $k, $v ]; return true; }
		);
		WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $d, $f = 0 ) => json_encode( $d, $f ) );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value = null ) => $value );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
		WP_Mock::userFunction( 'do_action' )->andReturn( null );

		// CU present. RulePusher's DEFAULT repo is CodeUnloader\Core\RuleRepository, which
		// this file declares — so can_push() and already_present_by_pattern() run for real.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( $cu_active );
		FakeCuRepo::$groups = [
			(object) [ 'id' => 11, 'name' => 'AA Scanner — Safe' ],
			(object) [ 'id' => 22, 'name' => 'AA Scanner — Aggressive' ],
		];
		if ( $cu_holds_the_rule ) {
			FakeCuRepo::$rules[] = (object) [
				'url_pattern' => 'https://s.com/p', 'match_type' => 'exact', 'asset_handle' => 'h-one',
				'asset_type'  => 'css', 'device_type' => 'all', 'group_id' => 22,
			];
		}

		return ( new ScannerAjax() )->do_build_result( 'job-xyz', 'tok-abc' );
	}

	/** The history row as it stands after the last write. */
	private function final_history_record(): array {
		foreach ( array_reverse( $this->option_writes ) as $w ) {
			if ( 'cu_scanner_history' === $w[0] ) { return $w[1][0]; }
		}
		return [];
	}

	/** Sanity: the fixture really does produce a refundable page. */
	public function test_fixture_yields_a_duplicate_only_page(): void {
		$this->run_build( true, [ 'ok' => true, 'refunded' => 1 ] );
		$this->assertCount( 1, $this->refund_posts, 'the refund endpoint must have been called' );
		$this->assertSame( 1, json_decode( $this->refund_posts[0]['args']['body'], true )['refund_pages'],
			'exactly one page is wholly-duplicate in this fixture' );
	}

	/**
	 * AC-16: update_status() ASSIGNS status (class-scan-history.php:36), so the refund
	 * write must re-pass $hist_status. Driving the real path means dropping it flips the
	 * persisted status to '' — which a hand-rolled double could never show.
	 */
	public function test_refund_write_preserves_the_history_status(): void {
		$this->run_build( true, [ 'ok' => true, 'refunded' => 1 ] );
		$record = $this->final_history_record();

		$this->assertSame( 'complete', $record['status'],
			'the refund write must re-pass the same status — update_status ASSIGNS it' );
		$this->assertSame( 1, $record['credits_refunded'] );
	}

	/** credits_used keeps its own meaning — the refund is shown beside it, never netted in. */
	public function test_credits_used_is_not_netted_by_the_refund(): void {
		$this->run_build( true, [ 'ok' => true, 'refunded' => 1 ] );
		$record = $this->final_history_record();
		$this->assertSame( 1, $record['credits_used'], 'credits_used stays the gross charge' );
	}

	/** AC-8: a failing refund call must not break the build, and must not write the field. */
	public function test_refund_failure_is_non_fatal(): void {
		$out    = $this->run_build( true, null ); // 404 — SaaS older than this plugin
		$record = $this->final_history_record();

		$this->assertSame( 'complete', $record['status'] );
		$this->assertArrayNotHasKey( 'credits_refunded', $record, 'no claim landed => no field' );
		$this->assertArrayHasKey( 'safe_count', $out, 'the screen is still correct without it' );
	}

	/** No duplicate pages => no HTTP call at all. */
	public function test_no_duplicates_makes_no_refund_call(): void {
		$this->run_build( false, [ 'ok' => true, 'refunded' => 1 ] );
		$this->assertCount( 0, $this->refund_posts );
		$this->assertArrayNotHasKey( 'credits_refunded', $this->final_history_record() );
	}

	/** The Bearer MUST be the job_token, not the account api_key (SaaS runs hash_equals). */
	public function test_refund_is_authed_with_the_job_token(): void {
		$this->run_build( true, [ 'ok' => true, 'refunded' => 1 ] );
		$post = $this->refund_posts[0];

		$this->assertStringContainsString( '/cu-scanner/v1/credits/refund-duplicates', $post['url'] );
		$this->assertSame( 'Bearer tok-abc', $post['args']['headers']['Authorization'],
			'api_key Bearer would 401 — /credits/release has the same contract' );
		$body = json_decode( $post['args']['body'], true );
		$this->assertSame( 'tok-abc', $body['job_token'], 'body repeats the token for hash_equals' );
		$this->assertSame( 1, $body['refund_pages'] );
	}

	/** The SaaS response is authoritative — it may clamp below what we asked for. */
	public function test_saas_reported_count_wins_over_the_requested_one(): void {
		$this->run_build( true, [ 'ok' => true, 'refunded' => 0 ] );
		$this->assertSame( 0, $this->final_history_record()['credits_refunded'] );
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// AC-4 — the same field NAMES on every payload writer.
	//
	// The live return and the aias_last_result option are writers 1 and 2; scanner.js
	// and menu-badge.js are 3 and 4 and are covered by tests/js/result-truth-display
	// .test.js. The hazard is real and already in-tree: `aggressive_count` on the wire
	// vs `agg_count` on the persistence side. These assert the new fields do NOT
	// reproduce that split.
	// ─────────────────────────────────────────────────────────────────────────────

	/** The option value as last persisted by do_build_result(). */
	private function persisted_last_result(): array {
		foreach ( array_reverse( $this->option_writes ) as $w ) {
			if ( 'aias_last_result' === $w[0] ) { return $w[1]; }
		}
		return [];
	}

	/** P17: the real do_build_result() with the real dedupe — nothing precomputed. */
	public function test_live_payload_carries_already_present_and_per_page_all_already(): void {
		$out = $this->run_build( true, [ 'ok' => true, 'refunded' => 1 ] );

		$this->assertArrayHasKey( 'already_present', $out );
		$this->assertSame( [ 'safe' => 0, 'aggressive' => 1 ], $out['already_present'] );
		$this->assertArrayHasKey( 'all_already', $out['pages'][0] );
		$this->assertTrue(
			$out['pages'][0]['all_already'],
			'every rule this page produced was already in CU, so the client must render it as a zero-yield row'
		);
		$this->assertSame( 1, $out['credits_refunded'] );
	}

	/**
	 * The invariant the display leans on: a page is flagged all_already EXACTLY when it was
	 * counted for the credit-back. If these two ever diverge the screen shows 0 credits on a
	 * page the customer actually paid for (or the reverse) — the whole point of deriving the
	 * flag from the refund loop rather than recomputing it client-side.
	 */
	public function test_all_already_pages_match_the_refunded_page_count(): void {
		$out = $this->run_build( true, [ 'ok' => true, 'refunded' => 1 ] );

		$flagged = count( array_filter( $out['pages'], static fn( $p ) => ! empty( $p['all_already'] ) ) );
		$this->assertSame( 1, $flagged );
		$this->assertSame( $flagged, $out['credits_refunded'] );
	}

	/** A page with a genuinely NEW rule must not be flagged — it delivered value and was billed. */
	public function test_new_rule_page_is_not_flagged_all_already(): void {
		$out = $this->run_build( false, null ); // CU active, but does NOT hold the rule

		$this->assertFalse( $out['pages'][0]['all_already'] );
		$this->assertSame( [ 'safe' => 0, 'aggressive' => 0 ], $out['already_present'] );
	}

	public function test_persisted_option_carries_the_same_field_names(): void {
		$out       = $this->run_build( true, [ 'ok' => true, 'refunded' => 1 ] );
		$persisted = $this->persisted_last_result();

		$this->assertArrayHasKey( 'already_present', $persisted );
		$this->assertArrayHasKey( 'all_already', $persisted['pages'][0] );
		$this->assertArrayHasKey( 'cu_rules_active', $persisted );
		$this->assertSame(
			$out['already_present'],
			$persisted['already_present'],
			'identical shape on every writer — the agg_count/aggressive_count split is the bug class here'
		);
		$this->assertSame( $out['credits_refunded'], $persisted['credits_refunded'] );
		$this->assertSame( $out['cu_rules_active'], $persisted['cu_rules_active'] );
	}

	/**
	 * P17 — the ?nowpcu-off signal on the REAL config path. do_build_result() receives no
	 * override; Settings::get_omit_cu_bypass() reads the option itself. Without this the
	 * Step-4 copy silently falls back to "please rescan" on exactly the scans that must
	 * never say it.
	 *
	 * ⚠️ One run_build() per test METHOD: WP_Mock keeps the first userFunction('get_option')
	 * registration for the life of a test, so a second run in the same method would silently
	 * replay the first seed and the "on" case would assert against the "off" wiring.
	 */
	public function test_cu_rules_active_is_false_when_the_suffix_is_applied(): void {
		$off = $this->run_build( true, [ 'ok' => true, 'refunded' => 1 ], true, false );

		$this->assertFalse( $off['cu_rules_active'], 'suffix applied => CU rules were NOT live' );
		$this->assertFalse( $this->persisted_last_result()['cu_rules_active'] );
	}

	/**
	 * Operator ruling 2026-08-05 — THE GATE. With the `?nowpcu` suffix applied (the DEFAULT
	 * install state), Code Unloader is switched off for the scan, so the scan is a fresh full
	 * measurement and what CU already holds is irrelevant to it: no dedupe, no split claim, no
	 * netting, no credit-back, every A>0 page billed.
	 *
	 * This fixture is the WORST case for the gate — CU genuinely DOES hold the rule and the SaaS
	 * would happily honour a refund. If the gate regresses, all four assertions below flip, so
	 * this cannot pass vacuously.
	 */
	public function test_suffix_applied_disables_the_whole_result_truth_apparatus(): void {
		$out = $this->run_build( true, [ 'ok' => true, 'refunded' => 1 ], true, false );

		$this->assertNull(
			$out['already_present'],
			'no claim in either direction — the summary line must read exactly as it did pre-1.7.88b'
		);
		$this->assertFalse( $out['pages'][0]['all_already'], 'no netting: the row keeps its real S/A counts and gross credit' );
		$this->assertNull( $out['credits_refunded'], 'no credit-back is claimed for a fresh full scan' );
		$this->assertSame( [], $this->refund_posts, 'and the SaaS refund endpoint is never called at all' );
	}

	/** The mirror: with the suffix omitted the same fixture DOES refund. Proves the gate is the discriminator. */
	public function test_suffix_omitted_enables_the_apparatus_on_the_same_fixture(): void {
		$out = $this->run_build( true, [ 'ok' => true, 'refunded' => 1 ], true, true );

		$this->assertSame( [ 'safe' => 0, 'aggressive' => 1 ], $out['already_present'] );
		$this->assertTrue( $out['pages'][0]['all_already'] );
		$this->assertSame( 1, $out['credits_refunded'] );
		$this->assertCount( 1, $this->refund_posts );
	}

	public function test_cu_rules_active_is_true_when_the_suffix_is_omitted(): void {
		$on = $this->run_build( true, [ 'ok' => true, 'refunded' => 1 ], true, true );

		$this->assertTrue( $on['cu_rules_active'], 'suffix omitted => the scan ran with CU rules live' );
		$this->assertTrue( $this->persisted_last_result()['cu_rules_active'] );
	}

	/**
	 * AC-11 on the wire: CU unreachable => null, NOT 0. The UI must be able to tell
	 * "cannot know" from "nothing is already present".
	 */
	public function test_cu_unreachable_emits_null_already_present(): void {
		$out = $this->run_build( false, null, false ); // CU plugin inactive

		$this->assertNull( $out['already_present'] );
		// "Cannot know" must never render as a zero-yield row: false, not null, and never true.
		$this->assertFalse( $out['pages'][0]['all_already'] );
		$this->assertNull( $this->persisted_last_result()['already_present'] );
	}
}

/**
 * Stands in for Code Unloader's repository under its REAL class name, so RulePusher's
 * default resolves to it and the production path executes unmodified.
 */
class FakeCuRepo {
	public static array $rules  = [];
	public static array $groups = [];

	public static function get_all_rules(): array  { return self::$rules; }
	public static function get_all_groups(): array { return self::$groups; }
	public static function reset(): void { self::$rules = []; self::$groups = []; }
}

// ⚠️ Process-global by necessity: can_push() asks class_exists() for CU's REAL class name,
// so this is the only way to exercise the production activation path rather than injecting
// a repo RulePusher would never construct on its own. Every other suite gates on
// is_plugin_active (stubbed false there), which short-circuits before class_exists.
if ( ! class_exists( 'CodeUnloader\\Core\\RuleRepository' ) ) {
	class_alias( FakeCuRepo::class, 'CodeUnloader\\Core\\RuleRepository' );
}

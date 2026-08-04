<?php
namespace CUScanner\Tests;

use PHPUnit\Framework\TestCase;
use CUScanner\Admin\ScannerAjax;

/**
 * AC-1 (non-negativity) · AC-2 (predicate soundness) · AC-3 (per-page emission)
 * · AC-17 (ratchet + collision residual).
 *
 * Drives the pure attribution helper directly. Task 5's test drives the full
 * do_build_result() path with the real dedupe (P17) — this file pins the arithmetic.
 */
class ResultTruthAttributionTest extends TestCase {

	public function setUp(): void {
		\WP_Mock::setUp();
		// attribute_already_present() runs the REAL UrlPattern::from_url() and the REAL
		// AIAS_Scan_Status::classify(); both reach WP functions. Stubbing the boundary,
		// not the units under test.
		\WP_Mock::userFunction( 'wp_parse_url' )
			->andReturnUsing( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		\WP_Mock::userFunction( '__' )->andReturnUsing( fn( $s, $d = null ) => $s );
	}

	public function tearDown(): void { \WP_Mock::tearDown(); }

	private function page( string $url, string $status = 'done' ): array {
		return [ 'url' => $url, 'status' => $status ];
	}

	private function tally( int $safe, int $agg ): array {
		return [ 'safe' => $safe, 'aggressive' => $agg, 'needed' => 0 ];
	}

	/** AC-11 passthrough: null in => null totals, no per-page claim, no refund. */
	public function test_null_by_pattern_yields_no_claim(): void {
		$out = ScannerAjax::attribute_already_present(
			null,
			[ 0 => $this->tally( 0, 2 ) ],
			[ 0 => $this->page( 'https://s.com/p' ) ]
		);
		$this->assertNull( $out['totals'] );
		$this->assertSame( 0, $out['refund_pages'] );
		$this->assertNull( $out['per_page'][0] );
	}

	/** Single page, everything already in CU => refundable. */
	public function test_single_page_all_duplicate_qualifies(): void {
		$out = ScannerAjax::attribute_already_present(
			[ 'https://s.com/p' => [ 'safe' => 0, 'aggressive' => 2 ] ],
			[ 0 => $this->tally( 0, 2 ) ],
			[ 0 => $this->page( 'https://s.com/p' ) ]
		);
		$this->assertSame( 2, $out['totals']['aggressive'] );
		$this->assertSame( 1, $out['refund_pages'] );
		$this->assertSame( 2, $out['per_page'][0] );
	}

	/** A page with one new rule is not duplicate-only. */
	public function test_partially_new_page_does_not_qualify(): void {
		$out = ScannerAjax::attribute_already_present(
			[ 'https://s.com/p' => [ 'safe' => 0, 'aggressive' => 1 ] ],
			[ 0 => $this->tally( 0, 2 ) ],
			[ 0 => $this->page( 'https://s.com/p' ) ]
		);
		$this->assertSame( 1, $out['totals']['aggressive'] );
		$this->assertSame( 0, $out['refund_pages'] );
	}

	/**
	 * AC-1: a ratchet-restored rule for an ABSENT page inflates A_P above B_P.
	 * The group-level min() must keep already_total <= totals so "X new" >= 0.
	 */
	public function test_already_total_never_exceeds_totals(): void {
		$out = ScannerAjax::attribute_already_present(
			[ 'https://s.com/p' => [ 'safe' => 0, 'aggressive' => 7 ] ], // CU has more than this scan found
			[ 0 => $this->tally( 0, 2 ) ],
			[ 0 => $this->page( 'https://s.com/p' ) ]
		);
		$this->assertSame( 2, $out['totals']['aggressive'], 'clamped to the scan total' );
		$this->assertGreaterThanOrEqual( 0, 2 - $out['totals']['aggressive'], '"X new" must never be negative' );
	}

	/**
	 * AC-2a — THE case per-page clamping gets wrong. Two URLs collapse to one pattern:
	 * page 0's 2 rules are already in CU, page 1's 1 rule is NEW. A_P = 2, ΣB = 3.
	 * Group aggregation: min(2,3) = 2 => "1 new, 2 already" (correct).
	 * Per-page clamping would give min(2,2)+min(2,1) = 3 => "0 new" (wrong) and would
	 * refund page 1.
	 */
	public function test_pattern_collision_reports_the_new_rule_and_refunds_nobody(): void {
		$out = ScannerAjax::attribute_already_present(
			[ 'https://s.com/p' => [ 'safe' => 0, 'aggressive' => 2 ] ],
			[ 0 => $this->tally( 0, 2 ), 1 => $this->tally( 0, 1 ) ],
			[ 0 => $this->page( 'https://s.com/p' ), 1 => $this->page( 'https://s.com/p?ver=2' ) ]
		);

		$this->assertSame( 2, $out['totals']['aggressive'], 'group already-count' );
		$this->assertSame( 0, $out['refund_pages'], 'fail-closed: group is not wholly duplicate' );
		$this->assertNull( $out['per_page'][0], 'multi-page group: no per-page claim' );
		$this->assertNull( $out['per_page'][1] );
	}

	/** AC-2b (CuJsonBuilder construction): an errored page has no tally at all. */
	public function test_errored_page_without_tally_does_not_inflate_refund(): void {
		$out = ScannerAjax::attribute_already_present(
			[ 'https://s.com/p' => [ 'safe' => 0, 'aggressive' => 2 ] ],
			[ 0 => $this->tally( 0, 2 ) ],            // index 1 absent — CuJsonBuilder skips error pages
			[ 0 => $this->page( 'https://s.com/p' ),
			  1 => $this->page( 'https://s.com/p?ver=2', 'error' ) ]
		);
		$this->assertSame( 1, $out['refund_pages'], 'only the delivered page counts' );
	}

	/**
	 * AC-2b (RATCHET construction) — the case a tally-only filter misses.
	 * recompute_by_page writes a row for EVERY pages_raw entry, so the errored page
	 * HAS a tally here. Only the delivered-class filter excludes it.
	 *
	 * ⚠️ The two pages must sit in SEPARATE pattern groups. If they share a URL they
	 * share a group, and the whole-group fail-closed check rejects the group before the
	 * delivered-class filter is ever consulted — the assertion would then pass or fail
	 * for a reason that has nothing to do with the filter it names. Distinct URLs keep
	 * this test pointed at its actual subject: group q is wholly duplicate and would be
	 * refunded on a tally-only check, and ONLY the delivered-class filter stops it.
	 */
	public function test_errored_page_with_a_ratchet_tally_does_not_inflate_refund(): void {
		$out = ScannerAjax::attribute_already_present(
			[
				'https://s.com/p' => [ 'safe' => 0, 'aggressive' => 2 ],
				'https://s.com/q' => [ 'safe' => 0, 'aggressive' => 2 ],
			],
			[ 0 => $this->tally( 0, 2 ), 1 => $this->tally( 0, 2 ) ], // ratchet gave BOTH a tally
			[ 0 => $this->page( 'https://s.com/p' ),
			  1 => $this->page( 'https://s.com/q', 'error' ) ]
		);
		$this->assertSame( 1, $out['refund_pages'],
			'the errored page has a non-zero tally on this path; delivered-class must exclude it' );
	}

	/**
	 * AC-17 — bounded residual, recorded not fixed. On the ratchet path
	 * recompute_by_page gives EVERY page in a group the FULL per-pattern count, so
	 * B_P double-counts. Display may over-report "X new"; money still fails closed.
	 */
	public function test_ratchet_plus_collision_over_reports_but_refunds_nobody(): void {
		$out = ScannerAjax::attribute_already_present(
			[ 'https://s.com/p' => [ 'safe' => 0, 'aggressive' => 3 ] ],
			[ 0 => $this->tally( 0, 3 ), 1 => $this->tally( 0, 3 ) ], // full count on both
			[ 0 => $this->page( 'https://s.com/p' ), 1 => $this->page( 'https://s.com/p?ver=2' ) ]
		);

		$this->assertSame( 3, $out['totals']['aggressive'] );
		$this->assertSame( 0, $out['refund_pages'], 'money fails closed: 3 != 6' );
		// Documented residual: totals are 6, already is 3, so the screen shows "3 new"
		// when 0 are. Pre-existing double-count on this path; see spec R-3.
	}
}

<?php
// FU-KEPT-BADGE-HOVER-INFO — build_pages() row field `kept_breakdown`, the per-page
// [label, count] rows the kept-chip hover tooltip renders from (admin/js/scanner.js).
//
// WHY PRODUCER-SIDE: the chip's own number (`kept_count`) counts distinct
// '<handle>|<type>' COMPOSITES across BOTH `kept_protection` AND `kept_known_assets`
// (count_kept_composites), but only `kept_protection` is threaded onto the row — so the
// client has the number and not the identity set behind it (measured: 1 protection vs 9
// total on the R20 reference scan). Deriving the breakdown HERE, with the same composite
// dedup, keeps the tooltip's arithmetic consistent with the chip's number BY CONSTRUCTION
// — two predicates that must agree in two languages is the defect class this file's
// sibling (kept_count) already exists to close.
//
// MUST stay in step with ScannerAjax::aggregate_kept_protection(): same protection-first
// attribution, same composite dedup, same display_name guards, same zero-count drop, same
// strcasecmp order. The invariant test at the bottom is the tripwire for drift against
// count_kept_composites.
//
// Defensive-validated per the kept_protection / bypass_suffixes convention — $page is
// built from untrusted Railway response data.
use PHPUnit\Framework\TestCase;

final class ScanStatusKeptBreakdownTest extends TestCase {
	private function page( array $o ): array {
		return array_merge( [ 'url' => 'https://example.test/', 'status' => 'done', 'assets' => [], 'broken_devices' => [] ], $o );
	}

	private function row( array $o ): array {
		return AIAS_Scan_Status::build_pages( [ $this->page( $o ) ], [] )[0];
	}

	/** The key must exist on EVERY row — an absent key is a chip with no tooltip, silently. */
	public function test_key_is_always_present_on_the_row(): void {
		$this->assertArrayHasKey( 'kept_breakdown', $this->row( [] ) );
	}

	public function test_key_absent_legacy_row_yields_empty(): void {
		$this->assertSame( [], $this->row( [] )['kept_breakdown'] );
	}

	public function test_single_named_entry(): void {
		$r = $this->row( [ 'kept_protection' => [ [ 'display_name' => 'Cloudflare Turnstile', 'handles' => [ 'cf-turnstile|script' ] ] ] ] );
		$this->assertSame( [ [ 'label' => 'Cloudflare Turnstile', 'count' => 1 ] ], $r['kept_breakdown'] );
	}

	/** Composite unit, not entry unit: one entry, two handles → count 2 (the gravity-forms shape). */
	public function test_multi_handle_entry_counts_composites(): void {
		$r = $this->row( [ 'kept_known_assets' => [ [ 'display_name' => 'Gravity Forms', 'handles' => [ 'gform_gravityforms|script', 'gform_json|script' ] ] ] ] );
		$this->assertSame( [ [ 'label' => 'Gravity Forms', 'count' => 2 ] ], $r['kept_breakdown'] );
	}

	/**
	 * A composite reaching us under BOTH fields is counted ONCE, and the protection label
	 * claims it — the attribution a customer is least able to afford being wrong. Mirrors
	 * aggregate_kept_protection()'s field order exactly.
	 */
	public function test_composite_shared_across_fields_attributed_to_protection_once(): void {
		$r = $this->row( [
			'kept_protection'   => [ [ 'display_name' => 'Cloudflare Turnstile', 'handles' => [ 'shared|script' ] ] ],
			'kept_known_assets' => [ [ 'display_name' => 'Some Vendor', 'handles' => [ 'shared|script' ] ] ],
		] );
		$this->assertSame( [ [ 'label' => 'Cloudflare Turnstile', 'count' => 1 ] ], $r['kept_breakdown'] );
	}

	/** Same label across entries accumulates into one row. */
	public function test_duplicate_label_accumulates(): void {
		$r = $this->row( [ 'kept_known_assets' => [
			[ 'display_name' => 'WordPress core', 'handles' => [ 'wp-a11y|script' ] ],
			[ 'display_name' => 'WordPress core', 'handles' => [ 'wp-hooks|script' ] ],
		] ] );
		$this->assertSame( [ [ 'label' => 'WordPress core', 'count' => 2 ] ], $r['kept_breakdown'] );
	}

	/**
	 * A nameless entry still CONSUMES composites (naming rule R3 forbids raw WP handles in
	 * customer copy, so it can produce no row) — its handles must still dedupe, or a later
	 * named entry would double-claim them and the tooltip would overstate the chip's N.
	 */
	public function test_nameless_entry_consumes_composites_but_yields_no_row(): void {
		$r = $this->row( [ 'kept_protection' => [
			[ 'handles' => [ 'shared|script' ] ],
			[ 'display_name' => 'Named Later', 'handles' => [ 'shared|script' ] ],
		] ] );
		$this->assertSame( [], $r['kept_breakdown'], 'the nameless entry claimed the composite; the named one gained nothing and a zero-count row must never render' );
	}

	/** Zero NEW composites (all duplicates / no handles) → no row, never "(0)". */
	public function test_zero_count_label_is_dropped(): void {
		$r = $this->row( [ 'kept_known_assets' => [ [ 'display_name' => 'Empty Vendor', 'handles' => [] ] ] ] );
		$this->assertSame( [], $r['kept_breakdown'] );
	}

	/** Deterministic case-insensitive order, independent of payload order. */
	public function test_rows_sort_case_insensitively(): void {
		$r = $this->row( [ 'kept_known_assets' => [
			[ 'display_name' => 'stripe payments', 'handles' => [ 's|script' ] ],
			[ 'display_name' => 'Fathom Analytics', 'handles' => [ 'f|script' ] ],
		] ] );
		$this->assertSame( [ 'Fathom Analytics', 'stripe payments' ], array_column( $r['kept_breakdown'], 'label' ) );
	}

	/**
	 * @dataProvider junkProvider
	 * @param mixed $junk
	 */
	public function test_non_array_field_yields_empty( $junk ): void {
		$r = $this->row( [ 'kept_protection' => $junk, 'kept_known_assets' => $junk ] );
		$this->assertSame( [], $r['kept_breakdown'] );
	}

	public function junkProvider(): array {
		return [
			'string' => [ 'Cloudflare' ],
			'int'    => [ 3 ],
			'bool'   => [ true ],
			'null'   => [ null ],
			'object' => [ (object) [ 'count' => 2 ] ],
		];
	}

	public function test_junk_entries_and_junk_handles_are_filtered_without_warning(): void {
		$r = $this->row( [ 'kept_known_assets' => [
			'junk', 7, null,
			[ 'display_name' => 'Real Vendor', 'handles' => [ 'ok|script', '', 42, null ] ],
			[ 'display_name' => 3, 'handles' => [ 'numeric-name|script' ] ],
		] ] );
		$this->assertSame( [ [ 'label' => 'Real Vendor', 'count' => 1 ] ], $r['kept_breakdown'] );
	}

	/**
	 * THE INVARIANT (P17-flavored tripwire): when every entry is named, the breakdown's
	 * counts sum EXACTLY to the row's own kept_count — the number printed on the chip the
	 * tooltip hangs off. The two are read three pixels apart; if this drifts, a customer
	 * sees "9 kept" and a tooltip naming 7. Nameless entries legitimately open a gap
	 * (breakdown ≤ kept_count) — asserted separately below.
	 */
	public function test_named_breakdown_sums_to_kept_count(): void {
		$r = $this->row( [
			'kept_protection'   => [ [ 'display_name' => 'Cloudflare Turnstile', 'handles' => [ 'cf|script' ] ] ],
			'kept_known_assets' => [
				[ 'display_name' => 'Gravity Forms', 'handles' => [ 'gf1|script', 'gf2|script' ] ],
				[ 'display_name' => 'WordPress core', 'handles' => [ 'wp-a11y|script', 'wp-hooks|script', 'cf|script' ] ], // cf|script dupes across fields
			],
		] );
		$this->assertSame( $r['kept_count'], array_sum( array_column( $r['kept_breakdown'], 'count' ) ) );
		$this->assertSame( 5, $r['kept_count'], 'sanity: 6 handle mentions, 5 distinct composites' );
	}

	public function test_nameless_entries_leave_breakdown_below_kept_count(): void {
		$r = $this->row( [ 'kept_protection' => [
			[ 'handles' => [ 'anon|script' ] ],
			[ 'display_name' => 'Named', 'handles' => [ 'named|script' ] ],
		] ] );
		$this->assertSame( 2, $r['kept_count'] );
		$this->assertSame( 1, array_sum( array_column( $r['kept_breakdown'], 'count' ) ) );
	}
}

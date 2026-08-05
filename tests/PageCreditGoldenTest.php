<?php
namespace CUScanner\Tests;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * AC-7 — page_credit() and the Step-4 Credits column are UNCHANGED by result-truth.
 *
 * The spec deliberately does NOT extend page_credit(): it has two production callers
 * (billable_credit_total for History, build_pages for the per-URL Credits cell), its
 * neighbouring param carries a "DISPLAY-ONLY … never dictates billing" contract, and
 * its user_cancel early-return sits ABOVE the block an extension would have touched.
 * This file exists so a future change cannot quietly reintroduce that coupling.
 */
class PageCreditGoldenTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( fn( $s, $d = null ) => $s );
	}

	public function tearDown(): void { WP_Mock::tearDown(); }

	public function scanShapes(): array {
		return [
			'ok with findings'        => [ [ 'status' => 'done', 'url' => 'https://s.com/a' ], [ 'safe' => 1, 'aggressive' => 2 ], null,          1 ],
			'ok zero findings'        => [ [ 'status' => 'done', 'url' => 'https://s.com/b' ], [ 'safe' => 0, 'aggressive' => 0 ], null,          0 ],
			'ok zero findings + ET'   => [ [ 'status' => 'done', 'url' => 'https://s.com/c', 'extra_time_charged' => 1 ], [ 'safe' => 0, 'aggressive' => 0 ], null, 2 ],
			'error page'              => [ [ 'status' => 'error', 'url' => 'https://s.com/d' ], null,                          null,          0 ],
			'error page + ET'         => [ [ 'status' => 'error', 'url' => 'https://s.com/e', 'extra_time_charged' => 1 ], null, null,          1 ],
			'user_cancel zero find'   => [ [ 'status' => 'done', 'url' => 'https://s.com/f' ], [ 'safe' => 0, 'aggressive' => 0 ], 'user_cancel', 1 ],
			'null tally'              => [ [ 'status' => 'done', 'url' => 'https://s.com/g' ], null,                          null,          1 ],
		];
	}

	/**
	 * @dataProvider scanShapes
	 * ⚠️ The expected values are a GOLDEN snapshot of pre-change behaviour. If one fails,
	 * the correct response is to revert the production change — not to update the number.
	 */
	public function test_page_credit_is_byte_identical_to_the_golden_snapshot(
		array $page, ?array $tally, ?string $terminal_source, int $expected
	): void {
		$this->assertSame( $expected, \AIAS_Scan_Status::page_credit( $page, $tally, $terminal_source ) );
	}

	/** The user_cancel early-return sits above the zero-value block. Pin it explicitly. */
	public function test_user_cancel_still_bypasses_the_zero_value_rule(): void {
		$this->assertSame(
			1,
			\AIAS_Scan_Status::page_credit(
				[ 'status' => 'done', 'url' => 'https://s.com/x' ],
				[ 'safe' => 0, 'aggressive' => 0 ],
				'user_cancel'
			),
			'a zero-finding page on a user-cancelled scan still bills 1 — do not "fix" this'
		);
	}

	/** page_credit must take exactly 3 params: an already-count param was NOT added. */
	public function test_page_credit_signature_is_unchanged(): void {
		$m = new \ReflectionMethod( \AIAS_Scan_Status::class, 'page_credit' );
		$this->assertSame( 3, $m->getNumberOfParameters() );
		$this->assertSame( [ 'page', 'tally', 'terminal_source' ],
			array_map( fn( $p ) => $p->getName(), $m->getParameters() ) );
	}

	/**
	 * The Step-4 Credits column is the other half of AC-7: build_pages() must keep
	 * emitting page_credit()'s value, untouched by the new `already` cell that Task 5
	 * adds alongside it.
	 */
	public function test_step4_credits_cell_still_comes_from_page_credit(): void {
		$pages   = [ [ 'status' => 'done', 'url' => 'https://s.com/a' ] ];
		$by_page = [ 0 => [ 'safe' => 1, 'aggressive' => 2, 'needed' => 0 ] ];

		$rows = \AIAS_Scan_Status::build_pages( $pages, $by_page, false, null );

		$this->assertSame(
			\AIAS_Scan_Status::page_credit( $pages[0], $by_page[0], null ),
			$rows[0]['credits'],
			'the Credits column must remain page_credit() output, not a duplicate-aware figure'
		);
	}
}

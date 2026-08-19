<?php
namespace CUScanner\Tests;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * AC-14 — the History tab render.
 *
 * ⚠️ This file introduces the repo's FIRST view-render harness. Everything here asserts
 * on CAPTURED OUTPUT from an actual include. Do NOT "simplify" any of it to
 * file_get_contents + strpos: a source-text pin stays green if the annotation is
 * retyped, mis-keyed, left in a comment, or emitted unescaped — i.e. it cannot fail for
 * any mistake it exists to prevent. (P17(b); tasks/lessons.md 2026-08-02.)
 *
 * ⚠️ The view builds its own rows from ( new ScanHistory() )->get_all(), so records are
 * seeded through the OPTION the real ScanHistory reads. Assigning a local $history
 * before the include would be overwritten by the view's own call — and would have been
 * testing the fixture rather than the page.
 */
class HistoryRenderTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $s ) => (string) $s );
		WP_Mock::userFunction( 'esc_html_e' )->andReturnUsing( function ( $s, $d = null ) {
			echo htmlspecialchars( (string) $s, ENT_QUOTES );
		} );
		WP_Mock::userFunction( '__' )->andReturnUsing( fn( $s, $d = null ) => $s );
		WP_Mock::userFunction( 'admin_url' )->andReturn( 'https://example.test/wp-admin/admin-ajax.php' );
		WP_Mock::userFunction( 'wp_create_nonce' )->andReturn( 'nonce123' );
	}

	public function tearDown(): void { WP_Mock::tearDown(); }

	/** The harness: seed the option the view reads, include the view, capture its output. */
	private function render( array $history ): string {
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			fn( $k, $default = false ) => 'cu_scanner_history' === $k ? $history : $default
		);
		ob_start();
		include dirname( __DIR__ ) . '/admin/views/history-page.php';
		return (string) ob_get_clean();
	}

	private function record( array $overrides = [] ): array {
		return array_merge( [
			'job_id' => 'job1', 'domain' => 'example.test', 'page_count' => 3,
			'status' => 'complete', 'created_at' => '2026-08-03T00:00:00+00:00',
			'credits_used' => 3, 'safe_count' => 0, 'aggressive_count' => 2,
		], $overrides );
	}

	public function test_credits_cell_shows_returned_annotation_when_refunded(): void {
		$out = $this->render( [ $this->record( [ 'credits_refunded' => 2 ] ) ] );
		$this->assertStringContainsString( '3 (2 returned)', $out );
	}

	/** No refund => byte-identical to today. A regression here is a visible change. */
	public function test_credits_cell_is_unchanged_when_not_refunded(): void {
		$out = $this->render( [ $this->record() ] );
		$this->assertStringContainsString( '<td>3</td>', $out );
		$this->assertStringNotContainsString( 'returned', $out );
	}

	/** 🟢 create_record() never seeds credits_refunded — old rows must not warn or break. */
	public function test_missing_credits_refunded_key_renders_todays_output(): void {
		$rec = $this->record();
		unset( $rec['credits_refunded'] );
		$out = $this->render( [ $rec ] );
		$this->assertStringContainsString( '<td>3</td>', $out );
		$this->assertStringNotContainsString( 'returned', $out );
	}

	public function test_zero_refund_renders_no_annotation(): void {
		$out = $this->render( [ $this->record( [ 'credits_refunded' => 0 ] ) ] );
		$this->assertStringNotContainsString( 'returned', $out );
	}

	/** The partial branch composes from credits_used; its existing rationale is preserved. */
	public function test_partial_status_branch_includes_the_returned_count(): void {
		$out = $this->render( [ $this->record( [ 'status' => 'partial', 'credits_used' => 4, 'credits_refunded' => 1 ] ) ] );
		$this->assertStringContainsString( 'Partial', $out );
		$this->assertStringContainsString( '4 credits charged', $out );
		$this->assertStringContainsString( '1 returned', $out );
	}

	public function test_partial_without_refund_is_unchanged(): void {
		$out = $this->render( [ $this->record( [ 'status' => 'partial', 'credits_used' => 4 ] ) ] );
		$this->assertStringContainsString( 'Partial &mdash; 4 credits charged', $out );
		$this->assertStringNotContainsString( 'returned', $out );
	}

	/** Escaping: §13 requires it on this edit, and only a render can check it. */
	public function test_output_is_escaped(): void {
		$out = $this->render( [ $this->record( [ 'domain' => '<script>alert(1)</script>' ] ) ] );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $out );
		$this->assertStringContainsString( '&lt;script&gt;', $out );
	}

	/** The table header is a fixed 8-column shape — the annotation goes INSIDE a cell. */
	public function test_header_row_still_has_eight_columns(): void {
		$out = $this->render( [ $this->record() ] );
		$this->assertSame( 8, substr_count( $out, '<th>' ) );
	}

	/** The empty state must survive the edit. */
	public function test_empty_history_renders_the_first_scan_prompt(): void {
		$out = $this->render( [] );
		$this->assertStringContainsString( 'No scans yet.', $out );
	}

	public function test_history_summary_and_status_chips_are_derived_from_real_records(): void {
		$out = $this->render( [
			$this->record(),
			$this->record( [
				'job_id'          => 'job2',
				'page_count'      => 4,
				'credits_used'    => 4,
				'safe_count'      => 1,
				'aggressive_count'=> 2,
				'status'          => 'partial',
			] ),
		] );

		$this->assertStringContainsString( '<strong>2</strong><span>Total scans</span>', $out );
		$this->assertStringContainsString( '<strong>7</strong><span>URLs scanned</span>', $out );
		$this->assertStringContainsString( '<strong>7</strong><span>Credits used</span>', $out );
		$this->assertStringContainsString( '<strong>5</strong><span>Recommendations</span>', $out );
		$this->assertStringContainsString( 'cu-history-status cu-history-status--complete', $out );
		$this->assertStringContainsString( 'cu-history-status cu-history-status--partial', $out );
	}
}

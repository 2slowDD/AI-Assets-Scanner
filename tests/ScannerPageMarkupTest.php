<?php
namespace CUScanner\Tests;

use PHPUnit\Framework\TestCase;

class ScannerPageMarkupTest extends TestCase {
	public function test_scan_complete_summary_renders_directly_above_result_url_list(): void {
		$markup = file_get_contents( dirname( __DIR__ ) . '/admin/views/scanner-page.php' );

		$this->assertIsString( $markup );

		$summary_pos = strpos( $markup, 'id="cu-result-summary"' );
		$list_pos    = strpos( $markup, 'id="cu-result-url-list"' );
		$push_pos    = strpos( $markup, 'id="cu-push-result"' );

		$this->assertNotFalse( $summary_pos );
		$this->assertNotFalse( $list_pos );
		$this->assertNotFalse( $push_pos );
		$this->assertGreaterThan( $push_pos, $summary_pos );
		$this->assertLessThan( $list_pos, $summary_pos );
	}
}

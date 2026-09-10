<?php
namespace CUScanner\Tests;

use PHPUnit\Framework\TestCase;

class ScannerPageMarkupTest extends TestCase {
	public function test_scanner_page_provides_a_notice_anchor_before_the_designed_shell(): void {
		$markup = file_get_contents( dirname( __DIR__ ) . '/admin/views/scanner-page.php' );

		$this->assertIsString( $markup );
		$anchor_pos = strpos( $markup, 'class="screen-reader-text cu-admin-notice-anchor"' );
		$shell_pos  = strpos( $markup, 'class="cu-wrap"' );

		$this->assertNotFalse( $anchor_pos );
		$this->assertNotFalse( $shell_pos );
		$this->assertLessThan( $shell_pos, $anchor_pos );
	}

	public function test_scanner_page_exposes_the_redesigned_admin_regions(): void {
		$markup = file_get_contents( dirname( __DIR__ ) . '/admin/views/scanner-page.php' );

		$this->assertIsString( $markup );
		$this->assertStringContainsString( 'id="cu-readiness-card"', $markup );
		$this->assertStringContainsString( 'cu-discovery-card', $markup );
		$this->assertStringContainsString( 'cu-reservation-card', $markup );
		$this->assertStringContainsString( 'class="cu-scan-console', $markup );
		$this->assertStringContainsString( 'cu-completion-card', $markup );
		$this->assertStringContainsString( 'id="cu-metric-urls"', $markup );
		$this->assertStringContainsString( 'id="cu-metric-safe"', $markup );
		$this->assertStringContainsString( 'id="cu-metric-aggressive"', $markup );
		$this->assertStringContainsString( 'id="cu-metric-credits"', $markup );
		$this->assertStringContainsString( 'id="cu-metric-balance"', $markup );
		$this->assertStringNotContainsString( 'id="cu-metric-kept"', $markup );
		$this->assertStringContainsString( 'cu-recommendations-card', $markup );
		$this->assertStringContainsString( 'cu-results-shell', $markup );
		$this->assertStringContainsString( 'cu-results-guidance', $markup );
	}

	public function test_results_keep_the_blue_strip_and_use_the_approved_metric_and_sidebar_order(): void {
		$markup = file_get_contents( dirname( __DIR__ ) . '/admin/views/scanner-page.php' );

		$this->assertIsString( $markup );
		$banner_pos  = strpos( $markup, 'id="cu-banner-area"' );
		$shell_pos   = strpos( $markup, 'class="cu-results-shell"' );
		$urls_pos    = strpos( $markup, 'id="cu-metric-urls"' );
		$safe_pos    = strpos( $markup, 'id="cu-metric-safe"' );
		$agg_pos     = strpos( $markup, 'id="cu-metric-aggressive"' );
		$credits_pos = strpos( $markup, 'id="cu-metric-credits"' );
		$balance_pos = strpos( $markup, 'id="cu-metric-balance"' );

		foreach ( array( $banner_pos, $shell_pos, $urls_pos, $safe_pos, $agg_pos, $credits_pos, $balance_pos ) as $position ) {
			$this->assertNotFalse( $position );
		}
		$this->assertLessThan( $shell_pos, $banner_pos );
		$this->assertLessThan( $safe_pos, $urls_pos );
		$this->assertLessThan( $agg_pos, $safe_pos );
		$this->assertLessThan( $credits_pos, $agg_pos );
		$this->assertLessThan( $balance_pos, $credits_pos );
		$this->assertStringContainsString( 'id="cu-kept-assets-panel"', $markup );
		$this->assertStringNotContainsString( 'Safe rules are the safest to apply.', $markup );
	}

	public function test_active_scan_uses_the_reference_radar_without_dropping_live_data_targets(): void {
		$markup = file_get_contents( dirname( __DIR__ ) . '/admin/views/scanner-page.php' );

		$this->assertIsString( $markup );
		$this->assertStringContainsString( 'class="cu-radar-stage"', $markup );
		$this->assertGreaterThanOrEqual( 7, substr_count( $markup, 'class="cu-radar-blip' ) );
		$this->assertStringContainsString( 'class="cu-radar-sweep"', $markup );
		$this->assertStringContainsString( 'id="cu-target-stack-notice"', $markup );
		$this->assertStringContainsString( 'id="cu-progress-bar"', $markup );
		$this->assertStringContainsString( 'id="cu-progress-text"', $markup );
		$this->assertStringContainsString( 'id="cu-pages-tbody"', $markup );
	}

	public function test_settings_and_history_use_the_shared_operational_admin_shell(): void {
		$settings = file_get_contents( dirname( __DIR__ ) . '/admin/views/settings-page.php' );
		$history  = file_get_contents( dirname( __DIR__ ) . '/admin/views/history-page.php' );

		$this->assertIsString( $settings );
		$this->assertIsString( $history );
		$this->assertStringContainsString( 'id="cu-scanner-settings"', $settings );
		$this->assertStringContainsString( 'class="cu-settings-grid"', $settings );
		$this->assertStringContainsString( 'cu-settings-card--account', $settings );
		$this->assertStringContainsString( 'cu-settings-card--environment', $settings );
		$this->assertStringContainsString( 'id="cu-scanner-history"', $history );
		$this->assertStringContainsString( 'class="cu-history-summary"', $history );
		$this->assertStringContainsString( 'class="cu-history-table-card"', $history );
	}

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

	public function test_results_match_the_approved_reference_layout_without_changing_action_ids(): void {
		$markup = file_get_contents( dirname( __DIR__ ) . '/admin/views/scanner-page.php' );
		$script = file_get_contents( dirname( __DIR__ ) . '/admin/js/scanner.js' );

		$this->assertIsString( $markup );
		$this->assertIsString( $script );
		$this->assertStringContainsString( 'class="cu-results-shell"', $markup );
		$this->assertStringContainsString( 'class="cu-results-primary"', $markup );
		$this->assertStringContainsString( 'id="cu-results-success-count"', $markup );
		$this->assertStringContainsString( 'id="cu-kept-assets-panel"', $markup );
		$this->assertStringContainsString( 'id="cu-kept-details-toggle"', $markup );
		$this->assertStringContainsString( 'id="cu-ready-rule-total"', $markup );
		$this->assertStringContainsString( 'id="cu-cu-status-title"', $markup );
		$this->assertStringContainsString( 'id="cu-next-step-title"', $markup );
		$this->assertStringContainsString( 'id="cu-btn-download" class="button button-secondary"', $markup );
		$this->assertSame( 7, substr_count( $script, '<th><span class="cu-th-inner">' ) );

		$sync_pos     = strpos( $markup, 'id="cu-btn-sync"' );
		$push_pos     = strpos( $markup, 'id="cu-btn-push"' );
		$download_pos = strpos( $markup, 'id="cu-btn-download"' );
		$this->assertNotFalse( $sync_pos );
		$this->assertNotFalse( $push_pos );
		$this->assertNotFalse( $download_pos );
		$this->assertLessThan( $push_pos, $sync_pos );
		$this->assertLessThan( $download_pos, $push_pos );
	}

	/** FU 1.8.5 — the shared header byline reads "Powered by WPservice.pro" (renders all-caps via CSS); the link is unchanged. */
	public function test_the_three_admin_views_carry_the_powered_by_byline_with_the_unchanged_link(): void {
		foreach ( [ 'scanner-page.php', 'history-page.php', 'settings-page.php' ] as $view ) {
			$markup = file_get_contents( dirname( __DIR__ ) . '/admin/views/' . $view );
			$this->assertIsString( $markup, $view );
			$this->assertStringContainsString(
				'<span class="cu-header-by">Powered by <a href="https://wpservice.pro/" target="_blank" rel="noopener">WPservice.pro</a></span>',
				$markup,
				$view
			);
			$this->assertStringNotContainsString( 'class="cu-header-by">by <a', $markup, $view );
		}
	}

	/**
	 * 1.8.6 — the Step-3 live URL table's pager is STATIC markup: JS only toggles it, so a 2 s poll
	 * can never rebuild the buttons out from under keyboard focus. It starts hidden (one page needs
	 * no pager) and carries its OWN ids — Step 4 renders cu-url-prev / cu-url-next from JS into the
	 * same document, so reusing them would hand getElementById the wrong buttons.
	 */
	public function test_live_url_table_has_a_static_hidden_pager_with_its_own_ids(): void {
		$markup = file_get_contents( dirname( __DIR__ ) . '/admin/views/scanner-page.php' );

		$this->assertIsString( $markup );
		$this->assertStringContainsString( '<div class="cu-url-pager cu-live-pager" id="cu-live-pager" hidden>', $markup );
		$this->assertStringContainsString( '<button type="button" class="button" id="cu-live-prev">', $markup );
		$this->assertStringContainsString( '<span id="cu-live-page-label"></span>', $markup );
		$this->assertStringContainsString( '<button type="button" class="button" id="cu-live-next">', $markup );
		$this->assertStringNotContainsString( 'cu-url-prev', $markup );
		$this->assertStringNotContainsString( 'cu-url-next', $markup );

		$tbody_pos = strpos( $markup, 'id="cu-pages-tbody"' );
		$pager_pos = strpos( $markup, 'id="cu-live-pager"' );
		$this->assertNotFalse( $tbody_pos );
		$this->assertNotFalse( $pager_pos );
		$this->assertLessThan( $pager_pos, $tbody_pos );
	}

	/**
	 * 1.8.6 — the JS/view lockstep for the live pager. The Node harness pre-creates these ids by
	 * NAME, so the JS suite stays green whatever the view carries; only this test notices a rename
	 * on one side. Read from the script itself so a new lookup is covered without editing this list.
	 */
	public function test_every_live_pager_id_the_script_looks_up_exists_in_the_view(): void {
		$markup = file_get_contents( dirname( __DIR__ ) . '/admin/views/scanner-page.php' );
		$script = file_get_contents( dirname( __DIR__ ) . '/admin/js/scanner.js' );

		$this->assertIsString( $markup );
		$this->assertIsString( $script );
		preg_match_all( "/getElementById\(\s*'(cu-live-[\w-]+)'\s*\)/", $script, $m );
		$ids = array_values( array_unique( $m[1] ) );
		$this->assertGreaterThanOrEqual( 4, count( $ids ), 'the lookup regex went blind: ' . implode( ', ', $ids ) );
		foreach ( $ids as $id ) {
			$this->assertStringContainsString( 'id="' . $id . '"', $markup, "scanner.js looks up #{$id}, so the view must carry it" );
		}
	}
}

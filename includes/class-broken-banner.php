<?php
defined( 'ABSPATH' ) || exit;

/**
 * AIAS_Broken_Banner — dismissable admin notice for pages blocked during a scan.
 *
 * Render surface: the scan-results (Step 4) JS calls the AJAX dismiss endpoint;
 * the banner HTML itself is built in scanner.js using data from build_result.
 * This class owns: dismissal storage, the AJAX dismiss handler, and the submit-job
 * wipe hook.  The render() method is also provided for unit tests and any future
 * PHP-rendered surface.
 */
class AIAS_Broken_Banner {

	const OPTION_DISMISSALS = 'aias_dismissed_warnings';

	/**
	 * Returns HTML for the admin notice, or '' if nothing to show.
	 *
	 * @param array{
	 *   scan_id: string,
	 *   pages_blocked: array{desktop: int, mobile: int},
	 *   blocked_reasons: array<string, int>,
	 *   total_pages?: int
	 * } $payload
	 */
	public static function render( array $payload ): string {
		$scan_id     = $payload['scan_id'] ?? '';
		$blocked_d   = (int) ( $payload['pages_blocked']['desktop'] ?? 0 );
		$blocked_m   = (int) ( $payload['pages_blocked']['mobile']  ?? 0 );
		$reasons     = $payload['blocked_reasons'] ?? [];
		$total_pages = (int) ( $payload['total_pages'] ?? 0 );

		if ( $blocked_d + $blocked_m === 0 ) {
			return '';
		}

		$dismissed = get_option( self::OPTION_DISMISSALS, [] );
		if ( is_array( $dismissed ) && ! empty( $dismissed[ $scan_id ] ) ) {
			return '';
		}

		$copy = self::reason_copy( $reasons, $blocked_d, $blocked_m, $total_pages );

		ob_start();
		?>
		<div class="notice notice-warning aias-broken-banner" data-scan-id="<?php echo esc_attr( $scan_id ); ?>">
			<p><strong>&#9888; <?php echo esc_html__( 'Some pages couldn\'t be fully scanned', 'ai-assets-scanner' ); ?></strong></p>
			<p><?php echo wp_kses_post( $copy ); ?></p>
			<p>
				<button type="button" class="button aias-dismiss-banner">
					<?php esc_html_e( 'Got it — don\'t show again for this scan', 'ai-assets-scanner' ); ?>
				</button>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Builds the human-readable copy for the banner body.
	 *
	 * @param array<string, int> $reasons
	 */
	private static function reason_copy(
		array $reasons,
		int $blocked_d,
		int $blocked_m,
		int $total_pages
	): string {
		$bits = [];
		if ( $blocked_d > 0 ) {
			$bits[] = sprintf(
				/* translators: 1: blocked page count, 2: total page count */
				esc_html__( 'Desktop scanner blocked on %1$d of %2$d pages.', 'ai-assets-scanner' ),
				$blocked_d,
				$total_pages
			);
		}
		if ( $blocked_m > 0 ) {
			$bits[] = sprintf(
				/* translators: 1: blocked page count, 2: total page count */
				esc_html__( 'Mobile scanner blocked on %1$d of %2$d pages.', 'ai-assets-scanner' ),
				$blocked_m,
				$total_pages
			);
		}

		$reason_phrases = [];
		foreach ( $reasons as $key => $count ) {
			$reason_phrases[] = self::reason_phrase( (string) $key );
		}
		$reason_clause = $reason_phrases
			? sprintf( ' (%s)', implode( ', ', array_unique( $reason_phrases ) ) )
			: '';

		$action_clause = self::action_clause( $reasons );

		return implode( ' ', $bits ) . $reason_clause . ' ' . $action_clause;
	}

	/**
	 * Maps a blocked-reason key to a remediation category.
	 * 'rate'  → operator should space scans / raise rate limits.
	 * 'error' → server-side issue, retry later / check site health.
	 * 'bot'   → bot-protection or asymmetric stub; default for tier2_* + tier1_zero_bytes + unknown.
	 */
	public static function reason_category( string $reason ): string {
		switch ( $reason ) {
			case 'tier1_http_rate_limit':
				return 'rate';
			case 'tier1_http_4xx':
			case 'tier1_http_5xx':
			case 'tier1_transport_error':
				return 'error';
			default:
				return 'bot';
		}
	}

	/**
	 * Builds the per-reason action clause for the banner body.
	 *
	 * If all reasons map to the same remediation category, returns that
	 * category's clause. Mixed-category reasons fall back to the generic
	 * bot-protection clause to avoid misleading single-cause guidance.
	 *
	 * @param array<string, int> $reasons
	 */
	private static function action_clause( array $reasons ): string {
		$categories = [];
		foreach ( $reasons as $key => $count ) {
			$categories[] = self::reason_category( (string) $key );
		}
		$categories = array_values( array_unique( $categories ) );

		if ( count( $categories ) === 1 ) {
			if ( $categories[0] === 'rate' ) {
				$settings_url = esc_url( admin_url( 'admin.php?page=cu-scanner-settings#cu-cloudflare-waf-bypass' ) );
				return esc_html__(
					'Your server rate-limited the scanner. The rules from the unblocked device (if any) are complete and safe to apply. Wait a few minutes between scans, or temporarily raise rate limits during scans.',
					'ai-assets-scanner'
				) . ' ' . sprintf(
					/* translators: %s: URL to AI Assets Scanner settings WAF bypass section */
					wp_kses_post( __( 'Behind Cloudflare or another CDN? Set up the scanner rate-limit exemption so future scans aren\'t throttled — <a href="%s">open AI Assets Scanner settings</a>.', 'ai-assets-scanner' ) ),
					$settings_url
				);
			}
			if ( $categories[0] === 'error' ) {
				return esc_html__(
					'Your server returned an error or didn\'t respond. The rules from the unblocked device (if any) are complete and safe to apply. Try again later, or check site health.',
					'ai-assets-scanner'
				);
			}
		}

		$base = esc_html__(
			'Your bot protection denied the scanner. The rules from the unblocked device are complete and safe to apply. For full coverage, temporarily disable bot protection during scans.',
			'ai-assets-scanner'
		);
		if ( in_array( 'rate', $categories, true ) ) {
			$settings_url = esc_url( admin_url( 'admin.php?page=cu-scanner-settings#cu-cloudflare-waf-bypass' ) );
			$base .= ' ' . sprintf(
				/* translators: %s: URL to AI Assets Scanner settings WAF bypass section */
				wp_kses_post( __( 'Behind Cloudflare or another CDN? Set up the scanner rate-limit exemption so future scans aren\'t throttled — <a href="%s">open AI Assets Scanner settings</a>.', 'ai-assets-scanner' ) ),
				$settings_url
			);
		}
		return $base;
	}

	/**
	 * Returns a human-readable phrase for a known blocked-reason key.
	 * Unknown keys fall through to esc_html() of the raw key.
	 */
	public static function reason_phrase( string $reason ): string {
		switch ( $reason ) {
			case 'tier2_cf_challenge':
				return esc_html__( 'Cloudflare challenge', 'ai-assets-scanner' );
			case 'tier2_akamai_challenge':
				return esc_html__( 'Akamai Bot Manager', 'ai-assets-scanner' );
			case 'tier2_imperva_challenge':
				return esc_html__( 'Imperva WAF', 'ai-assets-scanner' );
			case 'tier2_waf_challenge':
				return esc_html__( 'firewall/WAF', 'ai-assets-scanner' );
			case 'tier2_unknown_challenge':
				return esc_html__( 'bot/firewall protection (unidentified)', 'ai-assets-scanner' );
			case 'tier2_rocket_loader_stub':
				return esc_html__( 'Cloudflare Rocket-Loader stub', 'ai-assets-scanner' );
			case 'tier2_small_body':
				return esc_html__( 'asymmetric stub response', 'ai-assets-scanner' );
			case 'tier1_zero_bytes':
				return esc_html__( 'empty response', 'ai-assets-scanner' );
			case 'tier1_http_4xx':
				return esc_html__( 'site denial (4xx)', 'ai-assets-scanner' );
			case 'tier1_http_5xx':
				return esc_html__( 'site error (5xx)', 'ai-assets-scanner' );
			case 'tier1_http_rate_limit':
				return esc_html__( 'rate limit (429)', 'ai-assets-scanner' );
			case 'tier1_transport_error':
				return esc_html__( 'unreachable', 'ai-assets-scanner' );
			default:
				return esc_html( $reason );
		}
	}

	/**
	 * Wipe all dismissals when a new scan starts.
	 * Call at the top of ScannerAjax::submit_job().
	 */
	public static function on_submit_job(): void {
		update_option( self::OPTION_DISMISSALS, [], false /* autoload off */ );
	}

	/**
	 * AJAX handler: mark a scan's banner as dismissed.
	 * Nonce: aias_dismiss_banner
	 * Capability: manage_options (same as every other scanner AJAX endpoint)
	 */
	public static function ajax_dismiss(): void {
		check_ajax_referer( 'aias_dismiss_banner' );

		// Rule 4 + 11: nonce alone is NOT authorization — capability check is mandatory.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		// Rule 24: wp_unslash before sanitize_text_field.
		$scan_id = sanitize_text_field( wp_unslash( $_POST['scan_id'] ?? '' ) );
		if ( $scan_id === '' ) {
			wp_send_json_error( 'missing_scan_id' );
		}

		$dismissed = get_option( self::OPTION_DISMISSALS, [] );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = [];
		}
		$dismissed[ $scan_id ] = true;
		update_option( self::OPTION_DISMISSALS, $dismissed, false );
		wp_send_json_success();
	}
}

add_action( 'wp_ajax_aias_dismiss_banner', [ 'AIAS_Broken_Banner', 'ajax_dismiss' ] );

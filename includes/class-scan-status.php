<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pure per-URL scan-status helper for the Step-4 results table.
 * Owns the status class/label and the credit rule. No WP/DB deps → unit-testable.
 */
class AIAS_Scan_Status {

	/**
	 * @param array $page One Railway page: { url, status, broken_devices?[] }.
	 * @return array{class:string,label:string,credits:int}
	 */
	public static function classify( array $page ): array {
		$status  = (string) ( $page['status'] ?? '' );

		if ( 'origin_unavailable' === $status ) {
			return [
				'class'   => 'skipped',
				'label'   => __( 'Origin unavailable', 'ai-assets-scanner' ),
				'credits' => 0,
			];
		}

		// FU-AAS-ET-CREDIT-DISPLAY (2026-06-02): Railway stamps `extra_time_charged` on pages that
		// ran a billed Extra-Time continuation (1:1 with the scan-level et_ran SaaS charges). Add the
		// +1 so the per-URL Credits column matches the amount billed. The flag arrives from the
		// (untrusted) Railway status response — coerce to a 0/1 int; it only ever produces an int credit.
		$et_credit = empty( $page['extra_time_charged'] ) ? 0 : 1;
		// Base done-credit: 1 for any completed page (ok/partial/blocked), 0 for error (origin_unavailable
		// already returned above). Final per-URL credit = base + ET.
		$credits = ( 'error' === $status ) ? $et_credit : ( 1 + $et_credit );

		// Affected device = entry naming desktop/mobile with a NON-EMPTY reason
		// (mirrors CuJsonBuilder::blocked_devices(); is_broken intentionally unused).
		$affected = [];
		$bd = $page['broken_devices'] ?? null;
		if ( is_array( $bd ) ) {
			foreach ( $bd as $entry ) {
				if ( ! is_array( $entry ) ) { continue; }
				$device = (string) ( $entry['device'] ?? '' );
				$reason = (string) ( $entry['reason'] ?? '' );
				if ( '' === $reason ) { continue; }
				if ( ( 'desktop' === $device || 'mobile' === $device ) && ! isset( $affected[ $device ] ) ) {
					$affected[ $device ] = $reason;
				}
			}
		}

		$bot_reason = null;
		foreach ( $affected as $reason ) {
			if ( 'bot' === AIAS_Broken_Banner::reason_category( $reason ) ) { $bot_reason = $reason; break; }
		}

		if ( null !== $bot_reason ) {
			return [
				'class'   => 'blocked',
				'label'   => sprintf( /* translators: %s reason */ __( 'Blocked: %s', 'ai-assets-scanner' ), AIAS_Broken_Banner::reason_phrase( $bot_reason ) ),
				'credits' => $credits,
			];
		}
		if ( 'error' === $status ) {
			$first = $affected ? AIAS_Broken_Banner::reason_phrase( (string) reset( $affected ) ) : '';
			return [
				'class'   => 'error',
				'label'   => $first ? sprintf( __( 'Error: %s', 'ai-assets-scanner' ), $first ) : __( 'Error', 'ai-assets-scanner' ),
				'credits' => $credits,
			];
		}
		if ( ! empty( $affected ) ) {
			$device = (string) array_key_first( $affected );
			return [
				'class'   => 'partial',
				'label'   => sprintf( /* translators: 1 device, 2 reason */ __( '%1$s failed: %2$s', 'ai-assets-scanner' ), ucfirst( $device ), AIAS_Broken_Banner::reason_phrase( $affected[ $device ] ) ),
				'credits' => $credits,
			];
		}
		return [ 'class' => 'ok', 'label' => __( 'OK', 'ai-assets-scanner' ), 'credits' => $credits ];
	}

	/**
	 * Single source of the per-URL billed credit. = classify() credits, EXCEPT an
	 * 'ok' page that produced 0 safe AND 0 aggressive rules ("0 new unloads", S:0 A:0)
	 * bills 0 — matching the worker's noopt exclusion (FU-NOOPT-ZERO-CREDIT). The zero
	 * applies to the BASE credit only; Extra-Time pages (extra_time_charged) stay billable.
	 *
	 * @param array      $page  the raw page (status, broken_devices, extra_time_charged).
	 * @param array|null $tally per-page {safe,aggressive,needed} from CuJsonBuilder by_page,
	 *                          or NULL when no tally is available — in which case the noopt
	 *                          override is NOT applied (legacy 1-per-ok behavior).
	 */
	public static function page_credit( array $page, ?array $tally ): int {
		$st      = self::classify( $page );
		$credits = (int) $st['credits'];
		if ( null !== $tally
			&& 'ok' === $st['class']
			&& 0 === (int) ( $tally['safe'] ?? 0 )
			&& 0 === (int) ( $tally['aggressive'] ?? 0 )
			&& empty( $page['extra_time_charged'] ) ) {
			return 0;
		}
		return $credits;
	}

	/**
	 * Build the per-URL pages[] payload for the Step-4 table.
	 *
	 * @param array $pages_raw Railway pages (the SAME array passed to CuJsonBuilder::build()).
	 * @param array $by_page   build()'s per-page tallies, keyed by the SAME page index.
	 * @return array<int,array> Sequential rows; error/absent pages get S/A/N = 0.
	 */
	public static function build_pages( array $pages_raw, array $by_page, bool $is_partial = false ): array {
		$rows = [];
		$n    = 0;
		foreach ( $pages_raw as $i => $page ) {
			$n++;
			$page  = (array) $page;
			$st    = self::classify( $page );
			// R2 1.7.43b: a page with NO captured assets on a CANCELLED/partial scan was cut
			// off in-flight (zero S/A/N) — show it as "Cancelled", not a misleading 0-rule
			// "OK", and don't bill it. A genuinely-scanned page lists its assets even when all
			// of them are needed (S:0 A:0 but N>0), so empty assets is the cut-off signal.
			if ( $is_partial && empty( $page['assets'] ) ) {
				$st = [
					'class'   => 'cancelled',
					'label'   => __( 'Cancelled — not scanned', 'ai-assets-scanner' ),
					'credits' => 0,
				];
			}
			$tally = $by_page[ $i ] ?? [ 'safe' => 0, 'aggressive' => 0, 'needed' => 0 ];
			$bail  = isset( $page['deadline_bail_count'] ) ? (int) $page['deadline_bail_count'] : 0;
			// FU-NOOPT-ZERO-CREDIT: noopt-aware credit (cancelled rows already forced to 0 above).
			$credit = ( 'cancelled' === $st['class'] )
				? 0
				: self::page_credit( $page, $by_page[ $i ] ?? null );
			$rows[] = [
				'n'            => $n,
				'url'          => (string) ( $page['url'] ?? '' ),
				'status_class' => $st['class'],
				'status_label' => $st['label'],
				'credits'      => $credit,
				'safe'         => (int) ( $tally['safe'] ?? 0 ),
				'aggressive'   => (int) ( $tally['aggressive'] ?? 0 ),
				'needed'       => (int) ( $tally['needed'] ?? 0 ),
				// FU-AAS-ET-CANDIDATE-COLUMN: ok-only allowlist. Positive `=== 'ok'` (NOT `!== 'error'`)
				// — also excludes partial/blocked/skipped per the do-NOT.
				'et_candidate' => ( $bail > 0 && 'ok' === $st['class'] ),
			];
		}
		return $rows;
	}
}

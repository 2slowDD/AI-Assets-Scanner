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

		$credits = ( 'error' === $status || 'origin_unavailable' === $status ) ? 0 : 1;

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
				'credits' => 0,
			];
		}
		if ( ! empty( $affected ) ) {
			$device = (string) array_key_first( $affected );
			return [
				'class'   => 'partial',
				'label'   => sprintf( /* translators: 1 device, 2 reason */ __( '%1$s failed: %2$s', 'ai-assets-scanner' ), ucfirst( $device ), AIAS_Broken_Banner::reason_phrase( $affected[ $device ] ) ),
				'credits' => 1,
			];
		}
		return [ 'class' => 'ok', 'label' => __( 'OK', 'ai-assets-scanner' ), 'credits' => 1 ];
	}

	/**
	 * Build the per-URL pages[] payload for the Step-4 table.
	 *
	 * @param array $pages_raw Railway pages (the SAME array passed to CuJsonBuilder::build()).
	 * @param array $by_page   build()'s per-page tallies, keyed by the SAME page index.
	 * @return array<int,array> Sequential rows; error/absent pages get S/A/N = 0.
	 */
	public static function build_pages( array $pages_raw, array $by_page ): array {
		$rows = [];
		$n    = 0;
		foreach ( $pages_raw as $i => $page ) {
			$n++;
			$page  = (array) $page;
			$st    = self::classify( $page );
			$tally = $by_page[ $i ] ?? [ 'safe' => 0, 'aggressive' => 0, 'needed' => 0 ];
			$bail  = isset( $page['deadline_bail_count'] ) ? (int) $page['deadline_bail_count'] : 0;
			$rows[] = [
				'n'            => $n,
				'url'          => (string) ( $page['url'] ?? '' ),
				'status_class' => $st['class'],
				'status_label' => $st['label'],
				'credits'      => (int) $st['credits'],
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

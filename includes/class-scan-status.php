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
	 * Single source of the per-URL billed credit.
	 *
	 * = classify() credits, EXCEPT the noopt display-zero mirroring the worker's
	 * isNonBillableNoopt (FU-NOOPT-ZERO-CREDIT + FU-BILLING-BLOCKED-NOOPT): a done-class
	 * row ('ok', 'blocked' or 'partial') that produced 0 safe AND 0 aggressive rules
	 * ("0 new unloads", S:0 A:0) displays 0 — blocked-device or not. The zero applies
	 * to the BASE credit only; Extra-Time pages (extra_time_charged) stay billable.
	 *
	 * CANCEL-AWARE (M1 display ruling, 2026-07-04): when the scan terminated by
	 * USER-CANCEL the worker bills ALL done pages with NO noopt zeroing (the /cancel
	 * full exception), so ALL display-zeroing is skipped here — rows sum to the charge.
	 *
	 * @param array       $page            the raw page (status, broken_devices, extra_time_charged).
	 * @param array|null  $tally           per-page {safe,aggressive,needed} from CuJsonBuilder by_page,
	 *                                     or NULL when no tally is available — in which case the noopt
	 *                                     override is NOT applied (legacy 1-per-ok behavior). Error-status
	 *                                     pages are always NULL here (absent from by_page).
	 * @param string|null $terminal_source whitelist-validated terminal source from build_result()
	 *                                     ('user_cancel'|'failed'|'paused_exhausted'|'killed') or NULL.
	 *                                     DISPLAY-ONLY trust class — never dictates billing.
	 */
	public static function page_credit( array $page, ?array $tally, ?string $terminal_source = null ): int {
		$st      = self::classify( $page );
		$credits = (int) $st['credits'];
		if ( 'user_cancel' === $terminal_source ) {
			return $credits;
		}
		if ( null !== $tally
			&& in_array( $st['class'], array( 'ok', 'blocked', 'partial' ), true )
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
	 * @param array       $pages_raw       Railway pages (the SAME array passed to CuJsonBuilder::build()).
	 * @param array       $by_page         build()'s per-page tallies, keyed by the SAME page index.
	 * @param bool        $is_partial      completed < total (cancelled/failed partial).
	 * @param string|null $terminal_source whitelist-validated terminal source (E3) or NULL — threads to
	 *                                     page_credit() for cancel-aware Credits rendering.
	 * @return array<int,array> Sequential rows; error/absent pages get S/A/N = 0.
	 */
	public static function build_pages( array $pages_raw, array $by_page, bool $is_partial = false, ?string $terminal_source = null ): array {
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
			// FU-NOOPT-ZERO-CREDIT + FU-BILLING-BLOCKED-NOOPT (E3): noopt-aware, cancel-aware
			// credit (cancelled/not-scanned rows already forced to 0 above — deliberately
			// ALSO on user-cancelled scans: those rows were never 'done', so E2 bills 0 for
			// them and the display stays consistent).
			$credit = ( 'cancelled' === $st['class'] )
				? 0
				: self::page_credit( $page, $by_page[ $i ] ?? null, $terminal_source );
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
				// Phase 2 Slice C (C-V1): "a billed ET continuation ran on this page" — the
				// discriminator the noopt-note copy needs. Selected-but-refunded ET stays false
				// (deliberate: no ET actually ran, retrying it is legitimate).
				'et_charged'   => ! empty( $page['extra_time_charged'] ),
				// FU-ABSENT-SAFE B2 — optimizer-bypass-suffix fact for the Step-4
				// "optimizer detected" note. $page['bypass_suffixes'] is stamped onto
				// BOTH internal and external rows by do_build_result() (class-scanner-ajax.php)
				// before this method runs, read back from the submit-time per-URL map
				// (cu_scanner_bypass_map_<job_id> transient) keyed by the final scan URL —
				// static suffix strings, not user input. Still defensive-validated here since
				// $page is otherwise built from untrusted Railway response data.
				'bypass_suffixes' => is_array( $page['bypass_suffixes'] ?? null )
					? array_values( array_filter( $page['bypass_suffixes'], 'is_string' ) )
					: [],
				// FU-VFM-MASKING (spec 2026-07-31 §3.2): devices whose visual channel was off for
				// this scan's verdict. ok-only (mirrors et_candidate); hard device whitelist —
				// untrusted Railway input, type-safe per the bypass_suffixes convention.
				'visual_channel_off' => ( 'ok' === $st['class'] && is_array( $page['visual_channel_off'] ?? null ) )
					? array_values( array_unique( array_intersect(
						array_filter( $page['visual_channel_off'], 'is_string' ),
						[ 'desktop', 'mobile' ]
					) ) )
					: [],
				// A2c — per-row "kept protection" chip. Same worker wire field (W6) that
				// aggregate_kept_protection() folds into the scan-level note; that one reads
				// $pages_raw directly, so WITHOUT this key the per-row chip would render never,
				// silently, behind a green suite. Defensive-validated per the bypass_suffixes
				// convention — $page is otherwise built from untrusted Railway response data.
				// The client reads this ONLY for its non-empty length and interpolates no
				// string out of it, so nothing here reaches markup unescaped.
				//
				// A4 F-1 — filtered to entries that CONTRIBUTE A HANDLE, not merely to entries
				// that ARE arrays. The note gate is the handle count, so an entry carrying only
				// a display_name used to render a chip with no note anywhere to explain it.
				// Deciding it HERE, once at the producer, is what keeps the chip's own gate a
				// plain `length > 0` rather than a second copy of the note's predicate in JS —
				// two predicates that must agree is the defect class this fix closes.
				// `?? null`, NOT `?? []` — load-bearing: `[]` IS an array, so an absent key would take
				// the TRUE branch and dereference $page['kept_protection'] unguarded. Same at both above.
				'kept_protection' => is_array( $page['kept_protection'] ?? null )
					? array_values( array_filter( $page['kept_protection'], [ self::class, 'entry_contributes_handle' ] ) )
					: [],
				// R20 — the number the per-row chip renders as "N kept", spanning BOTH keep
				// fields. Decided here, at the producer, for the same reason kept_protection's
				// filter is: the client then needs one plain `> 0` test instead of a second
				// copy of the note's predicate in JS, and two predicates that must agree is the
				// defect class this file exists to close.
				'kept_count' => self::count_kept_composites( $page ),
				// FU-KEPT-BADGE-HOVER-INFO — the [label, count] rows the chip's hover
				// tooltip names its assets from. Decided here, at the producer, for the
				// same reason kept_count is: the tooltip's arithmetic and the chip's
				// number are read three pixels apart, and deriving both from the same
				// composite-dedup walk is what keeps them consistent BY CONSTRUCTION
				// instead of by two predicates that must agree in two languages.
				'kept_breakdown' => self::build_kept_breakdown( $page ),
			];
		}
		return $rows;
	}

	/**
	 * A4 F-1 — does this kept_protection entry contribute at least one handle to the
	 * scan-level count?
	 *
	 * MUST stay in step with ScannerAjax::aggregate_kept_protection(), whose `count` is the
	 * number of distinct NON-EMPTY STRING handles: a display_name alone adds a VENDOR but
	 * never a COUNT. So an entry with a name and no usable handle must NOT produce a per-row
	 * chip — the note is the only place the explanatory legend appears, and a chip without it
	 * is a shield badge whose meaning is nowhere on the page.
	 *
	 * The two predicates live in different classes (dependency direction forbids this class
	 * calling into ScannerAjax), so they are NOT coupled by construction — they are coupled
	 * EXECUTABLY, by the invariant test in tests/ScanStatusKeptProtectionTest.php that runs
	 * BOTH real functions over the audit's payload shapes and asserts chip ⟺ note. If either
	 * predicate drifts, that test reds.
	 *
	 * D5: entries come from the untrusted Railway response, so every level is type-guarded.
	 *
	 * @param mixed $entry One element of the worker's kept_protection[].
	 */
	private static function entry_contributes_handle( $entry ): bool {
		if ( ! is_array( $entry ) || ! is_array( $entry['handles'] ?? null ) ) {
			return false;
		}
		foreach ( $entry['handles'] as $h ) {
			// Mirrors aggregate_kept_protection()'s per-handle test exactly.
			if ( is_string( $h ) && '' !== $h ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * R20 — how many kept assets this ONE page contributes, across both keep fields.
	 *
	 * UNIT: distinct '<handle>|<type>' COMPOSITES, the same unit
	 * ScannerAjax::aggregate_kept_protection() counts the scan-level headline in. Deliberately
	 * NOT the entry count: `gravity-forms` is a single entry carrying two handles
	 * (gform_gravityforms + gform_json), and the note's own word is "assets" — two files were
	 * kept, two files load. An entry-based count would render "1 kept" beside a note counting
	 * both, and the two numbers are read on the same screen.
	 *
	 * Both fields feed ONE set, so a composite appearing in both is counted ONCE. Spec AC-8
	 * says protection and known-asset keeps are disjoint today; this does not rely on that
	 * holding, because the failure mode if it ever stops holding is an inflated number in
	 * front of a customer.
	 *
	 * Do NOT explode( '|', … ) — the composite IS the identity, exactly as in the aggregation.
	 *
	 * D5: both fields arrive from the Railway worker — untrusted input under WP Compliance
	 * Rule 1 — so every level is is_array / is_string guarded and no shape of junk can fatal
	 * or inflate the count. Pure function, no WP calls.
	 *
	 * @param array<string,mixed> $page One raw Railway per-page result row.
	 */
	private static function count_kept_composites( array $page ): int {
		$composites = array();
		foreach ( array( 'kept_protection', 'kept_known_assets' ) as $field ) {
			// `?? null`, NOT `?? array()` — same load-bearing reason as the row field above.
			$entries = $page[ $field ] ?? null;
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) || ! is_array( $entry['handles'] ?? null ) ) {
					continue;
				}
				foreach ( $entry['handles'] as $h ) {
					if ( is_string( $h ) && '' !== $h ) {
						$composites[ $h ] = true;
					}
				}
			}
		}
		return count( $composites );
	}

	/**
	 * FU-KEPT-BADGE-HOVER-INFO — one page's kept assets as [ label, count ] rows, for the
	 * kept-chip hover tooltip.
	 *
	 * MUST stay in step with ScannerAjax::aggregate_kept_protection() — this is that walk,
	 * page-scoped: same protection-first field order (in the degenerate case of one
	 * composite reaching us under two labels, the protection label claims it — the
	 * attribution a customer is least able to afford being wrong), same dedupe on the FULL
	 * '<handle>|<type>' composite, same display_name guards (a nameless entry still
	 * CONSUMES composites — naming rule R3 forbids raw WP handles in customer copy, so it
	 * can produce no row, but letting a later named entry re-claim its handles would
	 * overstate the chip's N), same zero-count drop (never "(0)"), same case-insensitive
	 * order. And in step with count_kept_composites() above: both walk the same two fields
	 * with the same guards, so a fully-named page's counts sum exactly to kept_count — the
	 * number printed on the chip this tooltip hangs off (invariant pinned in
	 * ScanStatusKeptBreakdownTest).
	 *
	 * D5: both fields arrive from the Railway worker — untrusted input under WP Compliance
	 * Rule 1 — so every level is is_array / is_string guarded and no shape of junk can
	 * fatal or inflate a count. Pure function, no WP calls. The client interpolates NONE of
	 * this into markup: labels reach the DOM only via the title PROPERTY (ruling R19).
	 *
	 * @param array<string,mixed> $page One raw Railway per-page result row.
	 * @return array<int,array{label:string,count:int}>
	 */
	private static function build_kept_breakdown( array $page ): array {
		$seen   = array(); // Composite-string set — dedupe index, same unit as kept_count.
		$labels = array(); // display_name => count of NEW composites it contributed.
		foreach ( array( 'kept_protection', 'kept_known_assets' ) as $field ) {
			// `?? null`, NOT `?? array()` — same load-bearing reason as the row fields above.
			$entries = $page[ $field ] ?? null;
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$new = 0;
				$hs  = $entry['handles'] ?? null;
				if ( is_array( $hs ) ) {
					foreach ( $hs as $h ) {
						if ( ! is_string( $h ) || '' === $h || isset( $seen[ $h ] ) ) {
							continue;
						}
						$seen[ $h ] = true;
						++$new;
					}
				}
				$name = $entry['display_name'] ?? null;
				if ( ! is_string( $name ) || '' === $name ) {
					continue;
				}
				$labels[ $name ] = ( $labels[ $name ] ?? 0 ) + $new;
			}
		}

		$rows = array();
		foreach ( $labels as $label => $count ) {
			if ( $count < 1 ) {
				continue;
			}
			$rows[] = array(
				'label' => (string) $label,
				'count' => $count,
			);
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);
		return $rows;
	}
}

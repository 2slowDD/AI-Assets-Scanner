<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * RatchetMerger — demotion-aware union of R_orig and R_et.
 *
 * Policy (§3.4 of the ET Result Ratchet spec):
 *   The final rule set is never worse than the original on a *benign* rescan
 *   (floor), but never re-introduces a rule the rescan validated as page-breaking
 *   (F-DEG ceiling). Pure class — no WP/DB I/O.
 */
class RatchetMerger {

    // ─────────────────────────────────────────────────────────────────────
    // FAILSAFE_DEMOTE_CLASS — mirrors Phase A worker map (§3.6).
    // Anything not in this map → 'validated' (fail-closed).
    // ─────────────────────────────────────────────────────────────────────

    /** Wire sentinel value for a rescued asset — mirrors verifier.js rebuildFinalAsset(). */
    private const RESCUED_SENTINEL = 0.001;

    /**
     * Per-pattern count of distinct rules RESTORED from R_orig by the last
     * merge() call. Keyed by url_pattern; value = number of distinct recollapse
     * keys (url_pattern|asset_handle|asset_type|group_id) restored from R_orig
     * that were NOT already present in R_et. RULE-domain: a rule that applies to
     * both devices is restored as two per-device legs but counts ONCE — the
     * customer-facing S/A/N and the "↩ +N" badge are rule-domain, because
     * merge() returns recollapse(...).
     * Populated by merge(); reset at the start of each merge() call.
     *
     * @var array<string,int>
     */
    public array $recovered_by_pattern = [];

    /**
     * Structured decision trail of the last merge() call — diagnostic only,
     * read by the AAS boundary for WP_DEBUG_LOG-gated logging. Never alters
     * merge output. Shape: [ 'counts'=>[], 'outcomes'=>[outcome=>int], 'handles'=>[ {...} ] ].
     *
     * Note: r_et, r_orig, final and the 'outcomes' tallies are in the
     * per-device-leg domain (post-explode_all) — a rule that applies to both
     * devices counts as 2. The 'recovered' count is the exception: it mirrors
     * recovered_by_pattern and is RULE-domain (distinct recollapsed rules). This
     * mixed domain is intentional and diagnostic-only.
     *
     * @var array<string,mixed>
     */
    public array $last_merge_diag = [];

    private const FAILSAFE_DEMOTE_CLASS = [
        'aggressive_goto_exhausted' => 'benign',
        'control_probe_failed'      => 'benign',
        'visual_unattributable'     => 'validated',
        'no_offender_isolated'      => 'validated',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resolve a failsafe_demote trigger string to 'benign'|'validated'.
     * Unknown or empty triggers return 'validated' (fail-closed).
     *
     * @param string $trigger Raw failsafe_demote value from rescan page.
     * @return string 'benign'|'validated'
     */
    public function resolve_failsafe_class( string $trigger ): string {
        return self::FAILSAFE_DEMOTE_CLASS[ $trigger ] ?? 'validated';
    }

    /**
     * Composite identity key for a rule — excludes group_id so that
     * the same logical slot (url_pattern × handle × type × device) maps
     * to a single key regardless of whether it comes from group 1 or 2.
     *
     * @param array $rule CU rule array (7 keys).
     * @return string Pipe-delimited composite key.
     */
    public function identity_key( array $rule ): string {
        return implode( '|', [
            $rule['url_pattern'],
            $rule['asset_handle'],
            $rule['asset_type'],
            $rule['device_type'],
        ] );
    }

    /**
     * Normalise a rule set so every rule has an explicit device_type of
     * 'desktop' or 'mobile' (never 'all'). Used before set-algebra so
     * that per-device comparisons are unambiguous.
     *
     * @param array $rules Array of CU rule arrays.
     * @return array Normalised array — 'all' rules replaced by two legs.
     */
    public function explode_all( array $rules ): array {
        $out = [];
        foreach ( $rules as $r ) {
            if ( 'all' === $r['device_type'] ) {
                $out[] = array_merge( $r, [ 'device_type' => 'desktop' ] );
                $out[] = array_merge( $r, [ 'device_type' => 'mobile'  ] );
            } else {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Inverse of explode_all: collapse desktop+mobile legs that share the
     * same (url_pattern, asset_handle, asset_type, group_id) back to a
     * single rule with device_type='all'. Legs that do not pair — or that
     * share a slot but differ in group_id — are left as-is.
     *
     * @param array $rules Array of per-device CU rule arrays.
     * @return array Collapsed rule array.
     */
    public function recollapse( array $rules ): array {
        // Build a pairing index keyed by (url_pattern|handle|type|group_id).
        $pairs = [];
        foreach ( $rules as $i => $r ) {
            if ( 'all' === $r['device_type'] ) {
                // Already collapsed — carry through as-is.
                $pairs[ 'pass_' . $i ] = [ 'rule' => $r, 'done' => true ];
                continue;
            }
            $pair_key = $this->recollapse_key( $r );
            $pairs[ $pair_key ][ $r['device_type'] ] = $r;
        }

        $out = [];
        foreach ( $pairs as $key => $entry ) {
            if ( isset( $entry['done'] ) ) {
                $out[] = $entry['rule'];
                continue;
            }
            if ( isset( $entry['desktop'], $entry['mobile'] ) ) {
                // Both legs present — collapse.
                $out[] = array_merge( $entry['desktop'], [ 'device_type' => 'all' ] );
            } elseif ( isset( $entry['desktop'] ) ) {
                $out[] = $entry['desktop'];
            } elseif ( isset( $entry['mobile'] ) ) {
                $out[] = $entry['mobile'];
            }
        }
        return array_values( $out );
    }

    /**
     * Deduplicate on identity_key. On a conflict (same slot, different group_id):
     * keep the STRONGER group_id (aggressive=2 beats safe=1). Never downgrades.
     *
     * @param array $rules Array of CU rule arrays (may contain duplicates).
     * @return array Deduplicated array.
     */
    public function dedupe_resolve_conflicts( array $rules ): array {
        $map = [];
        foreach ( $rules as $r ) {
            $k = $this->identity_key( $r );
            if ( ! isset( $map[ $k ] ) ) {
                $map[ $k ] = $r;
            } elseif ( $r['group_id'] > $map[ $k ]['group_id'] ) {
                // Upgrade to the stronger group — never downgrade.
                $map[ $k ] = $r;
            }
        }
        return array_values( $map );
    }

    /**
     * Index rescan pages into a lookup used by merge().
     *
     * Returns:
     *   [
     *     'failsafe' => [ '<url_pattern>' => 'benign'|'validated' ],
     *     'asset'    => [ '<identity_key>' => [
     *                       'covered'     => bool,
     *                       'demoted'     => bool,
     *                       'demote_class'=> string|null,
     *                     ] ],
     *   ]
     *
     * url_pattern: computed the same way CuJsonBuilder::url_to_pattern() does —
     * scheme://host/path lowercased, no query, trailing slash only on root.
     * (Re-implemented here because url_to_pattern is private on CuJsonBuilder.)
     *
     * @param array $rescan_pages Pages array from the ET rescan.
     * @return array State index.
     */
    public function index_rescan_state( array $rescan_pages ): array {
        $failsafe = [];
        $asset    = [];

        foreach ( $rescan_pages as $page ) {
            $url     = $page['url'] ?? '';
            $pattern = $this->url_to_pattern( $url );

            // Whole-page failsafe.
            if ( isset( $page['failsafe_demote'] ) ) {
                $failsafe[ $pattern ] = $this->resolve_failsafe_class( (string) $page['failsafe_demote'] );
            }

            // Per-asset state.
            // page-level 'demote_class' is not part of the §3.4 policy — per-asset demote_class is used instead.
            foreach ( $page['assets'] ?? [] as $a ) {
                $handle      = (string) ( $a['handle'] ?? '' );
                $type        = $this->map_type( (string) ( $a['type'] ?? '' ) );
                $demote_class = isset( $a['demote_class'] ) ? (string) $a['demote_class'] : null;

                foreach ( [ 'desktop', 'mobile' ] as $dev ) {
                    $dev_data = $a[ $dev ] ?? [];
                    $coverage = (float) ( $dev_data['coverage'] ?? 0.0 );
                    $covered  = $coverage > self::RESCUED_SENTINEL; // real positive coverage (exclude RESCUED_SENTINEL)
                    // Demoted = verifier demoted this device's reading:
                    // bucket='needed' AND coverage is the RESCUED_SENTINEL wire value (0 < cov <= 0.001)
                    // OR bucket='needed' and coverage=0 (no coverage at all but classified as needed).
                    $bucket  = (string) ( $dev_data['bucket'] ?? '' );
                    $demoted = ( 'needed' === $bucket && ! $covered );

                    $key = implode( '|', [ $pattern, $handle, $type, $dev ] );
                    // Aggregate: if either device says demoted or covered, record both.
                    // We keep per-device slots separate — identity_key already encodes device.
                    if ( ! isset( $asset[ $key ] ) ) {
                        $asset[ $key ] = [
                            'covered'      => $covered,
                            'demoted'      => $demoted,
                            'demote_class' => $demote_class,
                        ];
                    } else {
                        // If already set from a prior pass (shouldn't happen in well-formed input),
                        // covered wins over not-covered; demote_class: first non-null wins.
                        if ( $covered ) {
                            $asset[ $key ]['covered'] = true;
                        }
                        if ( $demoted ) {
                            $asset[ $key ]['demoted'] = true;
                        }
                        if ( null === $asset[ $key ]['demote_class'] && null !== $demote_class ) {
                            $asset[ $key ]['demote_class'] = $demote_class;
                        }
                    }
                }
            }
        }

        return [ 'failsafe' => $failsafe, 'asset' => $asset ];
    }

    /**
     * Demotion-aware union: R_et ∪ selected(R_orig).
     * Implements a 7-step policy (see Step 1–7 annotations inline).
     *
     * @param array $r_orig_rules CU rule arrays from the original scan.
     * @param array $rescan_pages Pages array from the ET rescan.
     * @param array $flags        Forwarded to CuJsonBuilder::build() (Phase 2a flags etc.).
     * @return array Final merged CU rule array (recollapsed).
     */
    public function merge( array $r_orig_rules, array $rescan_pages, array $flags = [] ): array {
        // Reset per-merge state.
        $this->recovered_by_pattern = [];
        $this->last_merge_diag      = [ 'counts' => [], 'outcomes' => [], 'handles' => [] ];

        // Step 1: build R_et via CuJsonBuilder and explode to per-device legs.
        $r_et_raw = ( new CuJsonBuilder() )->build( $rescan_pages, $flags )['rules'];
        $r_et     = $this->explode_all( $r_et_raw );

        // Step 2: explode R_orig to per-device legs.
        $orig = $this->explode_all( $r_orig_rules );

        // Step 3: index rescan state for policy lookups.
        $state = $this->index_rescan_state( $rescan_pages );

        // Step 4: build the set of R_et identity_keys (for O(1) lookup).
        $r_et_keys = [];
        foreach ( $r_et as $r ) {
            $r_et_keys[ $this->identity_key( $r ) ] = true;
        }

        // Step 5: start with all of R_et.
        $final = $r_et;

        // recovered_by_pattern is RULE-domain (see the property docblock): a rule
        // restored on both devices is two per-device legs but ONE recollapsed rule.
        // Accumulate each restored leg's recollapse key into a per-pattern set, then
        // count distinct keys after the walk — never once per leg.
        $recovered_keys = [];

        // Step 6: walk R_orig and selectively restore rules not already in R_et.
        foreach ( $orig as $r ) {
            $ikey = $this->identity_key( $r );
            if ( isset( $r_et_keys[ $ikey ] ) ) {
                $this->record_diag( $r, 'in_r_et', null, null );
                continue;
            }

            $page_pattern = $r['url_pattern'];

            // Check whole-page failsafe first.
            if ( isset( $state['failsafe'][ $page_pattern ] ) ) {
                $fs = $state['failsafe'][ $page_pattern ];
                if ( 'benign' === $fs ) {
                    $final[] = $r;
                    $recovered_keys[ $page_pattern ][ $this->recollapse_key( $r ) ] = true;
                    $this->record_diag( $r, 'failsafe_benign', null, 'benign' );
                } else {
                    $this->record_diag( $r, 'failsafe_validated', null, $fs );
                }
                continue;
            }

            // Per-asset policy.
            $asset_state = $state['asset'][ $ikey ] ?? null;

            if ( null === $asset_state ) {
                $final[] = $r;
                $recovered_keys[ $page_pattern ][ $this->recollapse_key( $r ) ] = true;
                $this->record_diag( $r, 'absent_restore', null, null );
                continue;
            }

            if ( $asset_state['covered'] ) {
                $this->record_diag( $r, 'covered_drop', $asset_state['demote_class'], null );
                continue;
            }

            $dc = $asset_state['demote_class'];
            if ( 'benign' === $dc ) {
                $final[] = $r;
                $recovered_keys[ $page_pattern ][ $this->recollapse_key( $r ) ] = true;
                $this->record_diag( $r, 'benign_restore', $dc, null );
            } else {
                $this->record_diag( $r, ( 'validated' === $dc ) ? 'validated_drop' : 'unknown_drop', $dc, null );
            }
        }

        // Collapse the per-pattern recollapse-key sets to distinct-rule counts.
        foreach ( $recovered_keys as $pat => $keys ) {
            $this->recovered_by_pattern[ $pat ] = count( $keys );
        }

        $this->last_merge_diag['counts'] = [
            'r_et'      => count( $r_et ),
            'r_orig'    => count( $orig ),
            // 'recovered' is RULE-domain (distinct recollapsed rules); r_et/r_orig/
            // final and the 'outcomes' tallies stay leg-domain. Diagnostic-only.
            'recovered' => array_sum( $this->recovered_by_pattern ),
            'final'     => count( $final ),
        ];

        // Step 7: dedupe (resolve group_id conflicts) then recollapse device pairs.
        return $this->recollapse( $this->dedupe_resolve_conflicts( $final ) );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Test seams (used only in unit tests — do NOT call from production code)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Test seam: exposes url_to_pattern() for the parity test.
     * @internal
     */
    public function __test_url_to_pattern( string $url ): string {
        return $this->url_to_pattern( $url );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Convert a scanned URL to a CU url_pattern.
     * Matches CuJsonBuilder::url_to_pattern() exactly.
     *
     * @param string $url Raw URL from a rescan page.
     * @return string Normalised pattern.
     */
    private function url_to_pattern( string $url ): string {
        $parsed = wp_parse_url( $url );
        $scheme = strtolower( $parsed['scheme'] ?? 'https' );
        $host   = strtolower( $parsed['host']   ?? '' );
        $path   = $parsed['path'] ?? '/';
        if ( '/' !== $path ) {
            $path = rtrim( $path, '/' );
        }
        return $scheme . '://' . $host . $path;
    }

    /**
     * Map Railway asset type to CU asset type (mirrors CuJsonBuilder::map_type).
     *
     * @param string $type Raw type from Railway wire format.
     * @return string 'css'|'js'|$type
     */
    private function map_type( string $type ): string {
        return match ( $type ) {
            'style'  => 'css',
            'script' => 'js',
            default  => $type,
        };
    }

    /**
     * Device-independent recollapse key for a rule leg — the key on which
     * recollapse() pairs desktop+mobile legs back into one device_type='all'
     * rule (url_pattern|asset_handle|asset_type|group_id; excludes device_type,
     * unlike identity_key). Shared by recollapse() and merge()'s restore
     * counting so the two never drift.
     *
     * @param array $rule Per-device CU rule array.
     * @return string Pipe-delimited recollapse key.
     */
    private function recollapse_key( array $rule ): string {
        return implode( '|', [
            $rule['url_pattern'],
            $rule['asset_handle'],
            $rule['asset_type'],
            $rule['group_id'],
        ] );
    }

    /**
     * Append one Step-6 decision to the diagnostic trail. Diagnostic only.
     *
     * @param array       $r              Exploded per-device R_orig rule.
     * @param string      $outcome        One of the eight Step-6 outcomes.
     * @param string|null $demote_class   Per-asset demote_class (null on non-per-asset branches).
     * @param string|null $failsafe_class Page-level failsafe class (only on failsafe outcomes).
     */
    private function record_diag( array $r, string $outcome, ?string $demote_class, ?string $failsafe_class ): void {
        $this->last_merge_diag['outcomes'][ $outcome ] =
            ( $this->last_merge_diag['outcomes'][ $outcome ] ?? 0 ) + 1;
        $this->last_merge_diag['handles'][] = [
            'handle'         => $r['asset_handle'],
            'type'           => $r['asset_type'],
            'device'         => $r['device_type'],
            'url_pattern'    => $r['url_pattern'],
            'outcome'        => $outcome,
            'demote_class'   => $demote_class,
            'failsafe_class' => $failsafe_class,
        ];
    }
}

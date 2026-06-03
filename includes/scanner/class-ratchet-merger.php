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
     * Per-pattern count of rules RESTORED from R_orig by the last merge() call.
     * Keyed by url_pattern; value = number of per-device rule legs re-included
     * from R_orig that were NOT already present in R_et.
     * Populated by merge(); reset at the start of each merge() call.
     *
     * @var array<string,int>
     */
    public array $recovered_by_pattern = [];

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
            $pair_key = implode( '|', [
                $r['url_pattern'],
                $r['asset_handle'],
                $r['asset_type'],
                $r['group_id'],
            ] );
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

        // Step 6: walk R_orig and selectively restore rules not already in R_et.
        foreach ( $orig as $r ) {
            $ikey    = $this->identity_key( $r );
            if ( isset( $r_et_keys[ $ikey ] ) ) {
                // Already in R_et — skip (no duplicate).
                continue;
            }

            // Determine the page url_pattern for this rule.
            $page_pattern = $r['url_pattern'];

            // Check whole-page failsafe first.
            if ( isset( $state['failsafe'][ $page_pattern ] ) ) {
                if ( 'benign' === $state['failsafe'][ $page_pattern ] ) {
                    $final[] = $r; // Restore.
                    $this->recovered_by_pattern[ $page_pattern ] = ( $this->recovered_by_pattern[ $page_pattern ] ?? 0 ) + 1;
                }
                // validated → drop (do nothing).
                continue;
            }

            // Per-asset policy.
            $asset_state = $state['asset'][ $ikey ] ?? null;

            if ( null === $asset_state ) {
                // Rescan never addressed this asset/page — treat as benign absent → restore.
                $final[] = $r;
                $this->recovered_by_pattern[ $page_pattern ] = ( $this->recovered_by_pattern[ $page_pattern ] ?? 0 ) + 1;
                continue;
            }

            if ( $asset_state['covered'] ) {
                // Asset is genuinely in use — drop.
                continue;
            }

            $dc = $asset_state['demote_class'];
            if ( 'benign' === $dc ) {
                $final[] = $r; // Restore.
                $this->recovered_by_pattern[ $page_pattern ] = ( $this->recovered_by_pattern[ $page_pattern ] ?? 0 ) + 1;
            }
            // 'validated' OR null/unknown → fail-closed → drop.
        }

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
}

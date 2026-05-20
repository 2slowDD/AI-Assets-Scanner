<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

class CuJsonBuilder {
    private const VERSION         = '1.4.1'; // Code Unloader import format version
    private const GROUP_SAFE      = 1;
    private const GROUP_AGGRESSIVE = 2;

    public function build( array $pages, array $flags = [] ): array {
        // Phase 2a — flags carried from the Railway scan-result payload (Option A;
        // see spec §4.3.1). Per Rule 1, this payload is third-party-API input —
        // cast defensively; missing/non-bool → false (D5 safety invariant).
        $combine_asymmetric_absent_enabled = (bool) ( $flags['combine_asymmetric_absent_enabled'] ?? false );
        $visual_diff_enabled               = (bool) ( $flags['visual_diff_enabled'] ?? false );

        $rules = [];
        foreach ( $pages as $page ) {
            if ( ( $page['status'] ?? '' ) === 'error' ) continue;
            $url_pattern = $this->url_to_pattern( $page['url'] );
            foreach ( $page['assets'] ?? [] as $asset ) {
                $desktop = $this->classify( $asset['desktop'] );
                $mobile  = $this->classify( $asset['mobile'] );
                foreach ( $this->combine(
                    $url_pattern,
                    $asset['handle'],
                    $asset['type'],
                    $desktop,
                    $mobile,
                    $combine_asymmetric_absent_enabled,
                    $visual_diff_enabled
                ) as $rule ) {
                    $rules[] = $rule;
                }
            }
        }

        return [
            'version'     => self::VERSION,
            'exported_at' => gmdate( 'c' ),
            'groups'      => [
                [ 'id' => self::GROUP_SAFE,       'name' => 'CU Scanner — Safe',       'description' => 'Assets confirmed not loaded on these pages' ],
                [ 'id' => self::GROUP_AGGRESSIVE, 'name' => 'CU Scanner — Aggressive', 'description' => 'Assets loaded but zero passive coverage. Verify before enabling.' ],
            ],
            'rules' => $rules,
        ];
    }

    /**
     * Returns 'absent' (loaded=false), 'aggressive' (loaded with zero coverage),
     * or 'needed' (loaded with positive coverage).
     *
     * AUTHORITATIVE source is `$device_data['bucket']` — emitted by Railway
     * scanner's rebuildFinalAsset() using the raw-coverage classifier
     * (RESCUED_SENTINEL aware). Reading bucket directly avoids re-deriving
     * from {loaded, coverage}, which loses the verifier's rescue signal once
     * coverage is wire-encoded as 0.001.
     *
     * Validation: bucket must be one of the three known values. Anything else
     * (missing field from older Railway versions, tampered payload, future
     * unknown enum value) falls through to the legacy {loaded, coverage}
     * derivation as a safety net. The legacy path has the original
     * F-DEG-blind bug — it cannot recognize a rescued !loaded asset — so the
     * fallback should only fire on truly malformed input. Treat as
     * defense-in-depth, not the primary code path.
     *
     * 2026-04-25 fix: 'absent' replaces the previous 'safe' classification.
     * Playwright's CSS coverage can miss late-injected stylesheets on the cold
     * (desktop-first) pass, returning loaded=false for assets that ARE on the
     * page. Treating !loaded as 'safe' caused false-positive Safe rules whose
     * push broke real rendering. Asymmetric 'absent' (one device only) is now
     * dropped in combine(); only dual-device confirmation produces a Safe rule.
     *
     * 2026-05-03 fix: Phase A demotion of inline-only handles produced
     * !loaded + coverage:0.001 (RESCUED_SENTINEL encoded). Legacy path
     * short-circuited on !loaded → 'absent' → safe rule emitted, breaking
     * eb_conditional_localize on production. Bucket field is now the
     * authoritative classification signal; legacy is fallback only.
     */
    private function classify( array $device_data ): string {
        // Validate the bucket field is one of the recognized enum values
        // before trusting it. Untrusted input rule applies even when the
        // sender is our own Railway scanner — defense in depth.
        $bucket = $device_data['bucket'] ?? null;
        if ( in_array( $bucket, [ 'absent', 'aggressive', 'needed' ], true ) ) {
            return $bucket;
        }
        // Legacy fallback — see jsdoc above. F-DEG-blind for !loaded rescued
        // assets; should not fire in current production.
        if ( ! $device_data['loaded'] ) return 'absent';
        if ( $device_data['coverage'] <= 0.0 ) return 'aggressive';
        return 'needed';
    }

    /**
     * Implements all 9 device-pair combinations.
     *
     * 'absent'     = loaded=false on this device. Playwright didn't see it —
     *                may be genuinely off the page OR a coverage-tracking miss.
     * 'aggressive' = loaded with zero coverage (verifier confirms safe to unload).
     * 'needed'     = loaded with positive coverage (in active use).
     *
     * Safe rules are only emitted when BOTH devices confirm 'absent' — single-
     * device 'absent' is treated as unreliable and dropped, since Playwright's
     * CSS coverage misses late-injected stylesheets on the cold pass.
     */
    private function combine(
        string $pattern,
        string $handle,
        string $type,
        string $desktop,
        string $mobile,
        bool $combine_asymmetric_absent_enabled = false,
        bool $visual_diff_enabled = false
    ): array {
        $safe = self::GROUP_SAFE;
        $agg  = self::GROUP_AGGRESSIVE;

        // Phase 2a structural guard (spec §4.1 + AC-V9a-7): BOTH flags must be
        // true. visual_diff=false is the safety-net-absent state — refuse the
        // asymmetric-absent emission unconditionally so we never ship a per-device
        // safe rule without the Phase A visual-diff demote backstop.
        $phase2a_effective = $combine_asymmetric_absent_enabled && $visual_diff_enabled;

        $map = [
            'absent,absent'         => [['all',     $safe]],
            'absent,aggressive'     => [['mobile',  $agg]],
            'absent,needed'         => $phase2a_effective ? [['desktop', $safe]] : [],
            'aggressive,absent'     => [['desktop', $agg]],
            'aggressive,aggressive' => [['all',     $agg]],
            'aggressive,needed'     => [['desktop', $agg]],
            'needed,absent'         => $phase2a_effective ? [['mobile',  $safe]] : [],
            'needed,aggressive'     => [['mobile',  $agg]],
            'needed,needed'         => [],
        ];

        $outputs = $map["{$desktop},{$mobile}"] ?? [];
        $rules   = [];
        foreach ( $outputs as [ $device_type, $group_id ] ) {
            $rules[] = [
                'url_pattern'  => $pattern,
                'match_type'   => 'exact',
                'asset_handle' => $handle,
                'asset_type'   => $this->map_type( $type ),
                'device_type'  => $device_type,
                'group_id'     => $group_id,
                'source_label' => 'CU Scanner',
            ];
        }
        return $rules;
    }

    /**
     * Map Railway asset type to Code Unloader asset type.
     * Railway returns 'style'/'script'; CU DB enum is 'css'/'js'.
     */
    private function map_type( string $type ): string {
        return match ( $type ) {
            'style'  => 'css',
            'script' => 'js',
            default  => $type,
        };
    }

    /**
     * Convert scanned URL to Code Unloader url_pattern.
     * Strips query params (bypass tokens) and trailing slash (except root),
     * matching the format produced by PatternMatcher::normalize_url().
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
}

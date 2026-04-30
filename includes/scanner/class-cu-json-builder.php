<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

class CuJsonBuilder {
    private const VERSION         = '1.4.1'; // Code Unloader import format version
    private const GROUP_SAFE      = 1;
    private const GROUP_AGGRESSIVE = 2;

    public function build( array $pages ): array {
        $rules = [];
        foreach ( $pages as $page ) {
            if ( ( $page['status'] ?? '' ) === 'error' ) continue;
            $url_pattern = $this->url_to_pattern( $page['url'] );
            foreach ( $page['assets'] ?? [] as $asset ) {
                $desktop = $this->classify( $asset['desktop'] );
                $mobile  = $this->classify( $asset['mobile'] );
                foreach ( $this->combine( $url_pattern, $asset['handle'], $asset['type'], $desktop, $mobile ) as $rule ) {
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
     * 2026-04-25 fix: 'absent' replaces the previous 'safe' classification.
     * Playwright's CSS coverage can miss late-injected stylesheets on the cold
     * (desktop-first) pass, returning loaded=false for assets that ARE on the
     * page. Treating !loaded as 'safe' caused false-positive Safe rules whose
     * push broke real rendering. Asymmetric 'absent' (one device only) is now
     * dropped in combine(); only dual-device confirmation produces a Safe rule.
     */
    private function classify( array $device_data ): string {
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
    private function combine( string $pattern, string $handle, string $type, string $desktop, string $mobile ): array {
        $safe = self::GROUP_SAFE;
        $agg  = self::GROUP_AGGRESSIVE;

        $map = [
            'absent,absent'         => [['all',     $safe]],
            'absent,aggressive'     => [['mobile',  $agg]],
            'absent,needed'         => [],
            'aggressive,absent'     => [['desktop', $agg]],
            'aggressive,aggressive' => [['all',     $agg]],
            'aggressive,needed'     => [['desktop', $agg]],
            'needed,absent'         => [],
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

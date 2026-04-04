<?php
namespace CUScanner\Scanner;

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

    /** Returns 'safe', 'aggressive', or 'needed' */
    private function classify( array $device_data ): string {
        if ( ! $device_data['loaded'] ) return 'safe';
        if ( $device_data['coverage'] <= 0.0 ) return 'aggressive';
        return 'needed';
    }

    /** Implements all 9 combining combinations from spec Section 6.3 */
    private function combine( string $pattern, string $handle, string $type, string $desktop, string $mobile ): array {
        $safe = self::GROUP_SAFE;
        $agg  = self::GROUP_AGGRESSIVE;

        $map = [
            'safe,safe'             => [['all',     $safe]],
            'safe,aggressive'       => [['desktop', $safe], ['mobile', $agg]],
            'safe,needed'           => [['desktop', $safe]],
            'aggressive,safe'       => [['desktop', $agg],  ['mobile', $safe]],
            'aggressive,aggressive' => [['all',     $agg]],
            'aggressive,needed'     => [['desktop', $agg]],
            'needed,safe'           => [['mobile',  $safe]],
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

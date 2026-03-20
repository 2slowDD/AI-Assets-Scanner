<?php
namespace CUScanner\Scanner;

class CuJsonBuilder {
    private const VERSION         = '1.3.6'; // Code Unloader import format version
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
                'url_pattern' => $pattern,
                'handle'      => $handle,
                'asset_type'  => $type,
                'device_type' => $device_type,
                'group_id'    => $group_id,
            ];
        }
        return $rules;
    }

    private function url_to_pattern( string $url ): string {
        $parsed = parse_url( $url );
        return ( $parsed['path'] ?? '/' ) . ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
    }
}

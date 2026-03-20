<?php
namespace CUScanner\Scanner;

/**
 * Pushes CU Scanner rules directly into Code Unloader's database
 * via CodeUnloader\Core\RuleRepository (static API).
 *
 * CU API (v1.3.9):
 *   RuleRepository::create_group( string $name, string $description ): int|\WP_Error
 *   RuleRepository::create_rule( array $data ): int|\WP_Error
 *     $data keys: url_pattern, match_type, asset_handle, asset_type,
 *                 device_type, group_id, source_label
 */
class RulePusher {
    private const CU_PLUGIN = 'code-unloader/code-unloader.php';
    private const CU_CLASS  = 'CodeUnloader\\Core\\RuleRepository';

    public function can_push(): bool {
        if ( ! is_plugin_active( self::CU_PLUGIN ) ) return false;
        return class_exists( self::CU_CLASS );
    }

    /**
     * Create groups and insert rules from a CU JSON output array.
     *
     * @param  array $cu_json Output of CuJsonBuilder::build()
     * @return array { safe_count: int, aggressive_count: int, error_count: int }
     * @throws \RuntimeException if Code Unloader is not available
     */
    public function push( array $cu_json ): array {
        if ( ! $this->can_push() ) {
            throw new \RuntimeException( 'Code Unloader is not active or RuleRepository class not found.' );
        }

        $repo = self::CU_CLASS;

        // Create or find the two scanner groups
        $group_ids = [];
        foreach ( $cu_json['groups'] as $group_def ) {
            $existing = $this->find_group_by_name( $repo, $group_def['name'] );
            if ( $existing !== null ) {
                $group_ids[ $group_def['id'] ] = $existing;
            } else {
                $result = $repo::create_group( $group_def['name'], $group_def['description'] ?? '' );
                if ( is_wp_error( $result ) ) {
                    throw new \RuntimeException( 'Failed to create group: ' . $result->get_error_message() );
                }
                $group_ids[ $group_def['id'] ] = $result;
            }
        }

        $safe_count       = 0;
        $aggressive_count = 0;
        $error_count      = 0;

        // Group ID 1 = Safe, Group ID 2 = Aggressive (matches CuJsonBuilder constants)
        $safe_group_id       = $group_ids[1] ?? null;
        $aggressive_group_id = $group_ids[2] ?? null;

        foreach ( $cu_json['rules'] as $rule ) {
            $cu_group_id = $rule['group_id'] === 1 ? $safe_group_id : $aggressive_group_id;

            $result = $repo::create_rule( [
                'url_pattern'  => $rule['url_pattern'],
                'match_type'   => 'exact',
                'asset_handle' => $rule['handle'],
                'asset_type'   => $this->map_asset_type( $rule['asset_type'] ),
                'device_type'  => $rule['device_type'],
                'group_id'     => $cu_group_id,
                'source_label' => 'CU Scanner',
            ] );

            if ( is_wp_error( $result ) ) {
                $error_count++;
            } elseif ( $rule['group_id'] === 1 ) {
                $safe_count++;
            } else {
                $aggressive_count++;
            }
        }

        return [
            'safe_count'       => $safe_count,
            'aggressive_count' => $aggressive_count,
            'error_count'      => $error_count,
        ];
    }

    /** Map CuJsonBuilder asset type ('style'/'script') to CU asset type ('css'/'js'). */
    private function map_asset_type( string $type ): string {
        return match ( $type ) {
            'style'  => 'css',
            'script' => 'js',
            default  => $type, // pass through 'css'/'js' if Railway already uses those
        };
    }

    /** Find an existing CU group by name. Returns DB group ID or null. */
    private function find_group_by_name( string $repo, string $name ): ?int {
        $groups = $repo::get_all_groups();
        foreach ( $groups as $group ) {
            if ( $group->name === $name ) {
                return (int) $group->id;
            }
        }
        return null;
    }
}

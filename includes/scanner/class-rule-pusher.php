<?php
namespace CUScanner\Scanner;

/**
 * Pushes CU Scanner rules directly into Code Unloader's database
 * via CodeUnloader\Core\RuleRepository (static API).
 *
 * CU API (v1.4.0):
 *   RuleRepository::create_group( string $name, string $description ): int|\WP_Error
 *   RuleRepository::create_rule( array $data ): int|\WP_Error
 *     $data keys: url_pattern, match_type, asset_handle, asset_type,
 *                 device_type, group_id, source_label
 *
 * Note: CuJsonBuilder already outputs rules in CU format (asset_handle, css/js types,
 * exact match_type, full URL pattern). RulePusher passes them through directly.
 */
class RulePusher {
    private const CU_PLUGIN = 'code-unloader/code-unloader.php';
    private const CU_CLASS  = 'CodeUnloader\\Core\\RuleRepository';

    public function __construct(
        private string $repo = self::CU_CLASS
    ) {}

    public function can_push(): bool {
        if ( ! is_plugin_active( self::CU_PLUGIN ) ) return false;
        return class_exists( $this->repo ); // use injected repo so FakeRuleRepository works in tests
    }

    /**
     * Snapshot active rules, push scanner groups/rules, then commit or rollback.
     *
     * @param  array $cu_json Output of CuJsonBuilder::build()
     * @return array { safe_count: int, aggressive_count: int, error_count: int }
     * @throws \RuntimeException if Code Unloader is not active or RuleRepository not found
     */
    public function push( array $cu_json ): array {
        if ( ! $this->can_push() ) {
            throw new \RuntimeException( 'Code Unloader is not active or RuleRepository class not found.' );
        }

        $repo    = $this->repo;
        $snapmgr = new SnapshotManager( $repo );

        // --- Phase 1: snapshot active rules (nothing disabled yet) ---
        $snapshot_attempted = false;
        if ( $snapmgr->has_active_rules() ) {
            $snapshot_attempted = true;
            $result = $snapmgr->snapshot();
            if ( \is_wp_error( $result ) ) {
                $snapmgr->rollback();
                return [ 'safe_count' => 0, 'aggressive_count' => 0, 'error_count' => 1 ];
            }
        }

        // --- Phase 2: push scanner groups and rules ---
        try {
            $stats = $this->do_push( $cu_json, $repo );
        } catch ( \Throwable $e ) {
            if ( $snapshot_attempted ) { $snapmgr->rollback(); }
            throw $e;
        }

        if ( $stats['error_count'] > 0 ) {
            // Any rule failure = roll back to preserve the invariant: old rules stay active
            // until a complete new set is successfully written (per spec).
            if ( $snapshot_attempted ) { $snapmgr->rollback(); }
            return $stats;
        }

        // --- Phase 3: commit (disable old groups) only on success ---
        if ( $snapshot_attempted ) {
            $snapmgr->commit();
        }

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Core push logic — create groups and insert rules. Extracted so push() can
     * wrap it cleanly in try/catch for rollback on exception.
     */
    private function do_push( array $cu_json, string $repo ): array {
        // Create or find the two scanner groups
        $group_ids = [];
        foreach ( $cu_json['groups'] as $group_def ) {
            $existing = $this->find_group_by_name( $repo, $group_def['name'] );
            if ( $existing !== null ) {
                // Clear old rules from this group before inserting the new set.
                // The rules are already preserved in the snapshot group at this point,
                // and the UNIQUE constraint on (url_pattern, asset_handle, ..., group_id)
                // would block re-inserting identical rules into the same group.
                $stale_ids = array_map(
                    fn( $r ) => (int) $r->id,
                    array_filter( $repo::get_all_rules(), fn( $r ) => (int) $r->group_id === $existing )
                );
                if ( ! empty( $stale_ids ) ) {
                    $repo::delete_rules( $stale_ids );
                }
                $group_ids[ $group_def['id'] ] = $existing;
            } else {
                $result = $repo::create_group( $group_def['name'], $group_def['description'] ?? '' );
                if ( \is_wp_error( $result ) ) {
                    throw new \RuntimeException( 'Failed to create group: ' . $result->get_error_message() );
                }
                $group_ids[ $group_def['id'] ] = $result;
            }
        }

        $safe_count        = 0;
        $aggressive_count  = 0;
        $error_count       = 0;
        $inserted_rule_ids = []; // track for cleanup on partial failure

        $safe_group_id       = $group_ids[1] ?? null;
        $aggressive_group_id = $group_ids[2] ?? null;

        foreach ( $cu_json['rules'] as $rule ) {
            $cu_group_id = $rule['group_id'] === 1 ? $safe_group_id : $aggressive_group_id;

            $result = $repo::create_rule( [
                'url_pattern'  => $rule['url_pattern'],
                'match_type'   => $rule['match_type']                    ?? 'exact',
                'asset_handle' => $rule['asset_handle'] ?? $rule['handle'] ?? '',
                'asset_type'   => $rule['asset_type'],
                'device_type'  => $rule['device_type'],
                'group_id'     => $cu_group_id,
                'source_label' => $rule['source_label']                  ?? 'CU Scanner',
            ] );

            if ( \is_wp_error( $result ) ) {
                $error_count++;
            } else {
                $inserted_rule_ids[] = (int) $result;
                if ( $rule['group_id'] === 1 ) {
                    $safe_count++;
                } else {
                    $aggressive_count++;
                }
            }
        }

        // Clean up partial Phase 2 writes if any rules failed
        if ( $error_count > 0 ) {
            foreach ( $inserted_rule_ids as $id ) {
                $repo::delete_rule( $id );
            }
        }

        return [
            'safe_count'       => $safe_count,
            'aggressive_count' => $aggressive_count,
            'error_count'      => $error_count,
        ];
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

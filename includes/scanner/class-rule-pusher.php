<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Pushes CU Scanner rules directly into Code Unloader's database
 * via CodeUnloader\Core\RuleRepository (static API).
 *
 * CU API (v1.4.0):
 *   RuleRepository::create_group( string $name, string $description ): int|\WP_Error
 *   RuleRepository::update_group( int $id, array $data ): bool
 *   RuleRepository::create_rule( array $data ): int|\WP_Error
 *   RuleRepository::delete_rule( int $id ): bool
 *
 * Push sequence:
 *   1. snapshot()            – backup ALL active rules to "Previously active [date]"
 *   2. bump_scanner_groups() – rename+disable old "CU Scanner — Safe/Aggressive"
 *   3. do_push()             – create fresh Safe (enabled) + Aggressive (disabled)
 *   4. commit()              – disable groups that were active before step 1
 */
class RulePusher {
    private const CU_PLUGIN = 'code-unloader/code-unloader.php';
    private const CU_CLASS  = 'CodeUnloader\\Core\\RuleRepository';

    public function __construct(
        private string $repo = self::CU_CLASS
    ) {}

    public function can_push(): bool {
        if ( ! is_plugin_active( self::CU_PLUGIN ) ) return false;
        return class_exists( $this->repo );
    }

    /**
     * Snapshot → version-bump → push → commit (or rollback on failure).
     *
     * @param  array $cu_json  Output of CuJsonBuilder::build()
     * @return array { safe_count: int, aggressive_count: int, error_count: int, error_message: string }
     * @throws \RuntimeException if Code Unloader is not active.
     */
    public function push( array $cu_json ): array {
        if ( ! $this->can_push() ) {
            throw new \RuntimeException( 'Code Unloader is not active or RuleRepository class not found.' );
        }

        $repo    = $this->repo;
        $snapmgr = new SnapshotManager( $repo );
        $vermgr  = new GroupVersionManager( $repo );

        // --- Phase 1: snapshot active rules (nothing disabled yet) ---
        $snapshot_attempted = false;
        if ( $snapmgr->has_active_rules() ) {
            $snapshot_attempted = true;
            $snap_result = $snapmgr->snapshot();
            if ( \is_wp_error( $snap_result ) ) {
                $snapmgr->rollback();
                return [
                    'safe_count'       => 0,
                    'aggressive_count' => 0,
                    'error_count'      => 1,
                    'error_message'    => 'Snapshot failed: ' . $snap_result->get_error_message(),
                ];
            }
        }

        // --- Phase 2: rename+disable old scanner groups ---
        $bump_result = $vermgr->bump_scanner_groups();
        if ( \is_wp_error( $bump_result ) ) {
            if ( $snapshot_attempted ) { $snapmgr->rollback(); }
            return [
                'safe_count'       => 0,
                'aggressive_count' => 0,
                'error_count'      => 1,
                'error_message'    => 'Version bump failed: ' . $bump_result->get_error_message(),
            ];
        }

        // --- Phase 3: push fresh scanner groups and rules ---
        try {
            $stats = $this->do_push( $cu_json, $repo );
        } catch ( \Throwable $e ) {
            $vermgr->rollback();
            if ( $snapshot_attempted ) { $snapmgr->rollback(); }
            throw $e;
        }

        if ( $stats['error_count'] > 0 ) {
            $vermgr->rollback();
            if ( $snapshot_attempted ) { $snapmgr->rollback(); }
            return $stats;
        }

        // --- Phase 4: commit (disable pre-push active groups) ---
        if ( $snapshot_attempted ) {
            $snapmgr->commit();
        }

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Create fresh scanner groups and insert rules.
     * Old groups were already renamed by GroupVersionManager — always create new.
     * On success, disables the Aggressive group (Safe stays enabled by default).
     */
    private function do_push( array $cu_json, string $repo ): array {
        // Create fresh groups — old ones were renamed, so these always succeed as new.
        $group_ids = [];
        foreach ( $cu_json['groups'] as $group_def ) {
            $result = $repo::create_group( $group_def['name'], $group_def['description'] ?? '' );
            if ( \is_wp_error( $result ) ) {
                throw new \RuntimeException( 'Failed to create group: ' . $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by caller's AJAX handler; passed to wp_send_json_error(), not rendered as HTML.
            }
            $group_ids[ $group_def['id'] ] = $result;
        }

        $safe_count        = 0;
        $aggressive_count  = 0;
        $error_count       = 0;
        $first_error       = '';
        $inserted_rule_ids = [];

        $safe_group_id       = $group_ids[1] ?? null;
        $aggressive_group_id = $group_ids[2] ?? null;

        foreach ( $cu_json['rules'] as $rule ) {
            $cu_group_id = $rule['group_id'] === 1 ? $safe_group_id : $aggressive_group_id;

            $result = $repo::create_rule( [
                'url_pattern'  => $rule['url_pattern'],
                'match_type'   => $rule['match_type']                      ?? 'exact',
                'asset_handle' => $rule['asset_handle'] ?? $rule['handle'] ?? '',
                'asset_type'   => $this->normalize_asset_type( $rule['asset_type'] ?? '' ),
                'device_type'  => $rule['device_type'],
                'group_id'     => $cu_group_id,
                'source_label' => $rule['source_label']                    ?? 'CU Scanner',
            ] );

            if ( \is_wp_error( $result ) ) {
                $msg = $result->get_error_message();
                // Skip duplicate-key violations — occurs when the scan JSON contains
                // the same rule more than once for the same group.
                // First copy wins; subsequent copies are silently dropped.
                if ( str_contains( $msg, 'Duplicate entry' ) || str_contains( $msg, 'uniq_rule' ) ) {
                    continue;
                }
                if ( ! $first_error ) {
                    $first_error = $msg;
                }
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

        if ( $error_count > 0 ) {
            // Clean up partial writes — caller will also rollback the snapshot.
            foreach ( $inserted_rule_ids as $id ) {
                $repo::delete_rule( $id );
            }
        } else {
            // Safe group stays enabled (CU default on group creation).
            // Aggressive group is saved-but-disabled — user enables when ready.
            if ( $aggressive_group_id !== null ) {
                $repo::update_group( $aggressive_group_id, [ 'enabled' => 0 ] );
            }
        }

        return [
            'safe_count'       => $safe_count,
            'aggressive_count' => $aggressive_count,
            'error_count'      => $error_count,
            'error_message'    => $first_error,
        ];
    }

    /** Map Railway/old-format asset types to CU DB enum values. */
    private function normalize_asset_type( string $type ): string {
        return match ( $type ) {
            'style'  => 'css',
            'script' => 'js',
            default  => $type,
        };
    }
}

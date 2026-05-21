<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Pushes AA Scanner rules directly into Code Unloader's database
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
 *   2. bump_scanner_groups() – rename+disable old "AA Scanner — Safe/Aggressive"
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

    /**
     * Append the scan's rules to the existing active CU groups (vs push() which
     * overwrites). No snapshot, no version-bump — purely additive. Both groups
     * end enabled. Duplicates are skipped via find_duplicate and reported as
     * already_present, never counted as appended (spec §4.3 / §4.4 — R9).
     *
     * @return array{appended_safe:int,appended_aggressive:int,already_present:int,error_count:int,error_message:string}
     * @throws \RuntimeException if Code Unloader is not active.
     */
    public function sync( array $cu_json ): array {
        if ( ! $this->can_push() ) {
            throw new \RuntimeException( 'Code Unloader is not active or RuleRepository class not found.' );
        }
        $repo = $this->repo;

        $group_ids = [];
        foreach ( $cu_json['groups'] as $group_def ) {
            $gid = $this->find_or_create_group( $repo, $group_def['name'], $group_def['description'] ?? '' );
            if ( \is_wp_error( $gid ) ) {
                // Group creation precedes rule iteration, so already_present is genuinely 0 here.
                return [ 'appended_safe' => 0, 'appended_aggressive' => 0, 'already_present' => 0, 'error_count' => 1, 'error_message' => 'Group create failed: ' . $gid->get_error_message() ];
            }
            $group_ids[ $group_def['id'] ] = $gid;
        }
        $safe_group_id       = $group_ids[1] ?? null;
        $aggressive_group_id = $group_ids[2] ?? null;

        $appended_safe = 0; $appended_aggressive = 0; $already_present = 0; $error_count = 0; $first_error = '';
        $inserted_rule_ids = [];

        foreach ( $cu_json['rules'] as $rule ) {
            $target_group_id = $rule['group_id'] === 1 ? $safe_group_id : $aggressive_group_id;
            $payload = $this->build_rule_payload( $rule, $target_group_id );

            if ( $repo::find_duplicate( $payload ) !== null ) {
                $already_present++;
                continue;
            }

            $result = $repo::create_rule( $payload );
            if ( \is_wp_error( $result ) ) {
                $msg = $result->get_error_message();
                // DB-UNIQUE backstop — treat as already_present, not error (find_duplicate can miss the prefix-191 column edge).
                if ( str_contains( $msg, 'Duplicate entry' ) || str_contains( $msg, 'uniq_rule' ) ) {
                    $already_present++;
                    continue;
                }
                if ( ! $first_error ) { $first_error = $msg; }
                $error_count++;
            } else {
                $inserted_rule_ids[] = (int) $result;
                if ( $rule['group_id'] === 1 ) { $appended_safe++; } else { $appended_aggressive++; }
            }
        }

        if ( $error_count > 0 ) {
            foreach ( $inserted_rule_ids as $id ) { $repo::delete_rule( $id ); }
            return [ 'appended_safe' => 0, 'appended_aggressive' => 0, 'already_present' => $already_present, 'error_count' => $error_count, 'error_message' => $first_error ];
        }

        $this->enable_both_groups( $repo, $safe_group_id, $aggressive_group_id );

        return [ 'appended_safe' => $appended_safe, 'appended_aggressive' => $appended_aggressive, 'already_present' => $already_present, 'error_count' => 0, 'error_message' => '' ];
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

            $result = $repo::create_rule( $this->build_rule_payload( $rule, $cu_group_id ) );

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
            // Both groups active (Safe + Aggressive) — operator's chosen behavior.
            $this->enable_both_groups( $repo, $safe_group_id, $aggressive_group_id );
        }

        return [
            'safe_count'       => $safe_count,
            'aggressive_count' => $aggressive_count,
            'error_count'      => $error_count,
            'error_message'    => $first_error,
        ];
    }

    /** Enable both scanner groups (Safe + Aggressive). Idempotent; shared by do_push() + sync(). */
    private function enable_both_groups( string $repo, ?int $safe_group_id, ?int $aggressive_group_id ): void {
        if ( $safe_group_id !== null )       { $repo::update_group( $safe_group_id,       [ 'enabled' => 1 ] ); }
        if ( $aggressive_group_id !== null ) { $repo::update_group( $aggressive_group_id, [ 'enabled' => 1 ] ); }
    }

    /**
     * Return the id of the existing un-versioned group with this exact name, or
     * create it. The bump flow guarantees at most one un-versioned group per base
     * name; if (abnormally) more than one exists, the highest id (most recent) wins.
     */
    private function find_or_create_group( string $repo, string $name, string $description ): int|\WP_Error {
        $match = null;
        foreach ( $repo::get_all_groups() as $g ) {
            if ( $g->name === $name && ( $match === null || (int) $g->id > (int) $match->id ) ) { $match = $g; }
        }
        if ( $match !== null ) { return (int) $match->id; }
        return $repo::create_group( $name, $description );
    }

    /**
     * Build the create_rule payload from a CuJsonBuilder rule, applying the same
     * transforms CU stores (normalize_asset_type, match_type/handle/source defaults).
     * Shared by do_push() AND sync() so Sync's find_duplicate pre-check queries the
     * identical scope create_rule writes (spec §4.1.4 / §4.4 — R9).
     */
    private function build_rule_payload( array $rule, ?int $target_group_id ): array {
        return [
            'url_pattern'  => $rule['url_pattern'],
            'match_type'   => $rule['match_type']                      ?? 'exact',
            'asset_handle' => $rule['asset_handle'] ?? $rule['handle'] ?? '',
            'asset_type'   => $this->normalize_asset_type( $rule['asset_type'] ?? '' ),
            'device_type'  => $rule['device_type'],
            'group_id'     => $target_group_id,
            'source_label' => $rule['source_label']                    ?? 'AA Scanner',
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

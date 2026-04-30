<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshots active CU rules before a scanner push, then disables
 * old groups only after new rules are confirmed written.
 *
 * Two-phase commit:
 *   1. snapshot()  — copy active rules to disabled dated group
 *   2. push scanner rules (caller's responsibility)
 *   3. commit()    — disable old groups, delete ungrouped rules  (call on push success)
 *      rollback()  — delete snapshot                           (call on push failure)
 *
 * @param string $repo RuleRepository class name (injectable for testing)
 */
class SnapshotManager {

    private ?int   $snapshot_group_id  = null;
    private array  $inserted_rule_ids  = [];
    private array  $groups_to_disable  = [];
    private array  $ungrouped_rule_ids = [];

    public function __construct(
        private string $repo = 'CodeUnloader\\Core\\RuleRepository'
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /** Returns true if there are any rules in currently enabled groups. */
    public function has_active_rules(): bool {
        return ! empty( $this->get_active_rules() );
    }

    /**
     * Phase 1 — Create a disabled snapshot group and write snapshot-only rows
     * (cu_group_items) for every currently active rule. Does NOT create active
     * duplicates in cu_rules and does NOT disable any existing groups.
     *
     * Intentionally uses create_group_item(), not create_rule(): the scanner
     * snapshot is a historical record, not an active ruleset. create_rule()
     * would insert both an active cu_rules row and a snapshot — the active
     * row would be counted as an enforced rule even after the snapshot group
     * is disabled, inflating the Rules tab total.
     *
     * Returns true on success, WP_Error on failure.
     */
    public function snapshot(): true|\WP_Error {
        $repo         = $this->repo;
        $active_rules = $this->get_active_rules();

        if ( empty( $active_rules ) ) {
            return true;
        }

        // Collect IDs of currently enabled groups (for commit phase)
        foreach ( (array) $repo::get_all_groups() as $group ) {
            if ( ! empty( $group->enabled ) ) {
                $this->groups_to_disable[] = (int) $group->id;
            }
        }

        // Create the snapshot group (created enabled by default)
        $group_name = 'Previously active rules ' . gmdate( 'F j Y' );
        $group_id   = $repo::create_group( $group_name, '' );
        if ( \is_wp_error( $group_id ) ) {
            $this->groups_to_disable = []; // reset — nothing was written
            return $group_id;
        }
        $this->snapshot_group_id = $group_id;

        // Disable the snapshot group immediately so the UI shows it as history, not live.
        $repo::update_group( $group_id, [ 'enabled' => 0 ] );

        // Write a snapshot-only row per active rule (cu_group_items, not cu_rules).
        foreach ( $active_rules as $rule ) {
            // Track ungrouped rules — they have no group to disable, so commit() deletes them.
            if ( empty( $rule->group_id ) ) {
                $this->ungrouped_rule_ids[] = (int) $rule->id;
            }

            $result = $repo::create_group_item( $group_id, [
                'url_pattern'     => $rule->url_pattern,
                'match_type'      => $rule->match_type,
                'asset_handle'    => $rule->asset_handle,
                'asset_type'      => $rule->asset_type,
                'device_type'     => $rule->device_type,
                'condition_type'  => $rule->condition_type  ?? null,
                'condition_value' => $rule->condition_value ?? null,
                'condition_invert'=> $rule->condition_invert ?? 0,
                'label'           => $rule->label ?? null,
                'source_label'    => 'CU Scanner Snapshot',
            ] );

            if ( \is_wp_error( $result ) ) {
                $msg = $result->get_error_message();
                // Skip duplicate-key violations — the same rule identity can exist in
                // multiple active groups, but only one snapshot row fits per scope.
                // First wins; subsequent identical snapshots are silently dropped.
                if ( str_contains( $msg, 'Duplicate entry' )
                    || str_contains( $msg, 'uniq_group_item' )
                    || str_contains( $msg, 'uniq_rule' ) ) {
                    continue;
                }
                return $result; // caller must call rollback()
            }

            $this->inserted_rule_ids[] = (int) $result;
        }

        return true;
    }

    /**
     * Phase 3 (success path) — Disable all groups that were enabled at snapshot time,
     * and delete ungrouped rules (they have no group to disable; already in snapshot).
     * Call only after scanner rules have been successfully written.
     */
    public function commit(): void {
        $repo = $this->repo;
        foreach ( $this->groups_to_disable as $group_id ) {
            $repo::update_group( $group_id, [ 'enabled' => 0 ] );
        }
        foreach ( $this->ungrouped_rule_ids as $rule_id ) {
            $repo::delete_rule( $rule_id );
        }
        $this->groups_to_disable  = [];
        $this->ungrouped_rule_ids = [];
    }

    /**
     * Failure path — Delete the snapshot group (cascades to its cu_group_items rows)
     * and reset internal state. Does NOT touch any pre-existing groups.
     */
    public function rollback(): void {
        if ( $this->snapshot_group_id === null ) {
            return; // snapshot() was never called or never created the group
        }

        $repo = $this->repo;

        // delete_group() cascades: deactivates group_item-linked cu_rules rows (none,
        // since snapshot() no longer creates active rows) and removes all
        // cu_group_items rows for the group before deleting the cu_groups row.
        $repo::delete_group( $this->snapshot_group_id );

        // Reset internal state
        $this->snapshot_group_id  = null;
        $this->inserted_rule_ids  = [];
        $this->groups_to_disable  = [];
        $this->ungrouped_rule_ids = [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Returns all rules whose group is currently enabled, plus ungrouped rules (always active). */
    private function get_active_rules(): array {
        $repo = $this->repo;
        return array_filter(
            (array) $repo::get_all_rules(),
            fn( $rule ) => ! empty( $rule->group_enabled ) || empty( $rule->group_id )
        );
    }
}

<?php
namespace CUScanner\Scanner;

/**
 * Renames and disables existing CU Scanner groups before a new push,
 * creating a versioned history ("CU Scanner — Safe v1", "v2", …).
 *
 * Rules are REMOVED from old groups during bump — the UNIQUE constraint on
 * wp_cu_rules is table-wide (no group_id component), so keeping rules in
 * versioned groups would block re-inserting the same rules into new groups.
 * Cleared rules are stored internally and re-inserted if rollback() is called.
 *
 * Only touches groups whose name matches exactly one of the two base scanner
 * group names. All string comparisons use strict equality.
 */
class GroupVersionManager {

	/** Base names owned by the scanner — must match exactly what CuJsonBuilder emits. */
	private const SCANNER_GROUPS = [
		'CU Scanner — Safe',
		'CU Scanner — Aggressive',
	];

	public function __construct(
		private string $repo = 'CodeUnloader\\Core\\RuleRepository'
	) {}

	/** Tracks groups renamed by bump_single() for rollback: [ id => original_base_name ] */
	private array $renamed = [];

	/** Rules cleared from bumped groups, stored for rollback: [ group_id => [ rule_obj, ... ] ] */
	private array $cleared_rules = [];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Rename each existing base scanner group to "Base Name vN" (N = highest
	 * existing version + 1), disable it, and clear its rules. Groups that do
	 * not exist are silently skipped.
	 *
	 * @return true|\WP_Error  WP_Error on any DB failure.
	 */
	public function bump_scanner_groups(): true|\WP_Error {
		$repo       = $this->repo;
		$all_groups = (array) $repo::get_all_groups();

		foreach ( self::SCANNER_GROUPS as $base_name ) {
			$result = $this->bump_single( $repo, $all_groups, $base_name );
			if ( \is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Restore renamed groups to their original base names, re-enable them,
	 * and re-insert any rules that were cleared during bump.
	 * Safe to call even if bump_scanner_groups() was never called or found nothing.
	 */
	public function rollback(): void {
		$repo = $this->repo;
		foreach ( $this->renamed as $id => $original_name ) {
			$repo::update_group( $id, [ 'name' => $original_name, 'enabled' => 1 ] );

			// Re-insert rules that were cleared during bump
			foreach ( $this->cleared_rules[ $id ] ?? [] as $rule ) {
				$repo::create_rule( [
					'url_pattern'     => $rule->url_pattern,
					'match_type'      => $rule->match_type,
					'asset_handle'    => $rule->asset_handle,
					'asset_type'      => $rule->asset_type,
					'device_type'     => $rule->device_type,
					'condition_type'  => $rule->condition_type   ?? null,
					'condition_value' => $rule->condition_value  ?? null,
					'condition_invert'=> $rule->condition_invert ?? 0,
					'label'           => $rule->label            ?? null,
					'source_label'    => $rule->source_label     ?? '',
					'group_id'        => $id,
				] );
			}
		}
		$this->renamed       = [];
		$this->cleared_rules = [];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * @param  object[] $all_groups  Full group list from RuleRepository.
	 */
	private function bump_single( string $repo, array $all_groups, string $base_name ): true|\WP_Error {
		// Find base group by exact name — no regex, no user input involved.
		$base = null;
		foreach ( $all_groups as $g ) {
			if ( $g->name === $base_name ) {
				$base = $g;
				break;
			}
		}

		if ( $base === null ) {
			return true; // Group doesn't exist — nothing to do.
		}

		$next     = $this->next_version( $all_groups, $base_name );
		$new_name = $base_name . ' v' . $next;

		// Rename and disable in one call — avoids a partial-failure window.
		if ( $repo::update_group( (int) $base->id, [ 'name' => $new_name, 'enabled' => 0 ] ) === false ) {
			return new \WP_Error(
				'cu_scanner_version_failed',
				sprintf( 'Failed to rename and disable group "%s"', $base_name )
			);
		}

		// Track for rollback AFTER the rename succeeded.
		$this->renamed[ (int) $base->id ] = $base_name;

		// Clear rules from this group. The UNIQUE constraint on wp_cu_rules is
		// table-wide (no group_id), so old rules must be removed before the same
		// rules can be inserted into the fresh group. Rules are stored for rollback.
		$group_rules = array_values( array_filter(
			(array) $repo::get_all_rules(),
			fn( $r ) => (int) $r->group_id === (int) $base->id
		) );

		if ( ! empty( $group_rules ) ) {
			$rule_ids = array_map( fn( $r ) => (int) $r->id, $group_rules );
			$repo::delete_rules( $rule_ids );
			$this->cleared_rules[ (int) $base->id ] = $group_rules;
		}

		return true;
	}

	/**
	 * Scan all groups for "Base Name v{N}" patterns and return the next integer.
	 * Version suffixes are validated with preg_match and cast to int — no eval.
	 *
	 * @param  object[] $all_groups
	 */
	private function next_version( array $all_groups, string $base_name ): int {
		$prefix = $base_name . ' v';
		$max    = 0;

		foreach ( $all_groups as $g ) {
			if ( ! str_starts_with( $g->name, $prefix ) ) {
				continue;
			}
			$suffix = substr( $g->name, strlen( $prefix ) );
			// Only accept pure-integer suffixes to avoid matching "v1beta" etc.
			if ( preg_match( '/^\d+$/', $suffix ) === 1 ) {
				$max = max( $max, (int) $suffix );
			}
		}

		return $max + 1;
	}
}

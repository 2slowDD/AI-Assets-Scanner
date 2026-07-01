<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

class LastPushSyncUndo {
    private const OPTION = 'aias_last_push_sync_undo';

    public function store_from_summary( string $operation, string $job_id, array $summary ): void {
        $rule_ids = $this->positive_ints( $summary['created_rule_ids'] ?? array() );

        if ( empty( $rule_ids ) ) {
            delete_option( self::OPTION );
            return;
        }

        update_option(
            self::OPTION,
            array(
                'version'           => 1,
                'operation'         => in_array( $operation, array( 'push', 'sync' ), true ) ? $operation : 'sync',
                'job_id'            => sanitize_text_field( $job_id ),
                'created_at'        => gmdate( 'c' ),
                'rule_ids'          => $rule_ids,
                'group_ids'         => $this->positive_ints( $summary['group_ids'] ?? array() ),
                'created_group_ids' => $this->positive_ints( $summary['created_group_ids'] ?? array() ),
                'counts'            => array(
                    'safe'            => isset( $summary['safe_count'] ) ? absint( $summary['safe_count'] ) : absint( $summary['appended_safe'] ?? 0 ),
                    'aggressive'      => isset( $summary['aggressive_count'] ) ? absint( $summary['aggressive_count'] ) : absint( $summary['appended_aggressive'] ?? 0 ),
                    'already_present' => absint( $summary['already_present'] ?? 0 ),
                ),
            ),
            false
        );
    }

    public function state_for_ui(): array {
        $manifest = $this->manifest();

        if ( null === $manifest ) {
            return array(
                'available' => false,
            );
        }

        return array(
            'available'  => true,
            'operation'  => $manifest['operation'],
            'created_at' => $manifest['created_at'],
            'counts'     => $manifest['counts'],
        );
    }

    public function undo( string $repo ): array|\WP_Error {
        $manifest = $this->manifest();

        if ( null === $manifest ) {
            return new \WP_Error( 'aias_no_undo_manifest', 'No push/sync operation is available to undo.' );
        }

        $deleted = 0;
        $skipped = 0;

        foreach ( $manifest['rule_ids'] as $rule_id ) {
            $rule_id = (int) $rule_id;

            if ( is_callable( array( $repo, 'get_rule' ) ) && null === $repo::get_rule( $rule_id ) ) {
                $skipped++;
                continue;
            }

            if ( false === $repo::delete_rule( $rule_id ) ) {
                return new \WP_Error( 'aias_rule_delete_failed', 'Undo could not remove a Code Unloader rule. Please retry after checking Code Unloader.' );
            }

            $deleted++;
        }

        $disabled = 0;

        foreach ( $manifest['created_group_ids'] as $group_id ) {
            if ( false === $repo::update_group( (int) $group_id, array( 'enabled' => 0 ) ) ) {
                return new \WP_Error( 'aias_group_disable_failed', 'Undo removed rules but could not disable a newly created Code Unloader group. Please retry after checking Code Unloader.' );
            }

            $disabled++;
        }

        delete_option( self::OPTION );

        return array(
            'deleted_rule_count'   => $deleted,
            'skipped_rule_count'   => $skipped,
            'disabled_group_count' => $disabled,
        );
    }

    private function manifest(): ?array {
        $raw = get_option( self::OPTION, null );

        if ( ! is_array( $raw ) ) {
            return null;
        }

        $rule_ids = $this->positive_ints( $raw['rule_ids'] ?? array() );

        if ( empty( $rule_ids ) ) {
            return null;
        }

        return array(
            'operation'         => in_array( $raw['operation'] ?? '', array( 'push', 'sync' ), true ) ? (string) $raw['operation'] : 'sync',
            'created_at'        => sanitize_text_field( (string) ( $raw['created_at'] ?? '' ) ),
            'rule_ids'          => $rule_ids,
            'group_ids'         => $this->positive_ints( $raw['group_ids'] ?? array() ),
            'created_group_ids' => $this->positive_ints( $raw['created_group_ids'] ?? array() ),
            'counts'            => is_array( $raw['counts'] ?? null ) ? array(
                'safe'            => absint( $raw['counts']['safe'] ?? 0 ),
                'aggressive'      => absint( $raw['counts']['aggressive'] ?? 0 ),
                'already_present' => absint( $raw['counts']['already_present'] ?? 0 ),
            ) : array(
                'safe'            => 0,
                'aggressive'      => 0,
                'already_present' => 0,
            ),
        );
    }

    private function positive_ints( array $values ): array {
        $ids = array();

        foreach ( $values as $value ) {
            $id = absint( $value );

            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }

        return array_values( array_unique( $ids ) );
    }
}

<?php
namespace CUScanner;

defined( 'ABSPATH' ) || exit;

class ScanHistory {
    private const HISTORY_OPTION    = 'cu_scanner_history';
    private const JSON_OPTION_PREFIX = 'cu_scanner_json_';
    private const MAX_RECORDS       = 10;

    public function create_record( string $job_id, string $domain, int $page_count, string $status ): void {
        $records    = get_option( self::HISTORY_OPTION, [] );
        $new_record = [
            'job_id'           => $job_id,
            'domain'           => $domain,
            'page_count'       => $page_count,
            'status'           => $status,
            'created_at'       => gmdate( 'c' ),
            'credits_used'     => 0,
            'safe_count'       => 0,
            'aggressive_count' => 0,
        ];
        array_unshift( $records, $new_record );
        // Cap at MAX_RECORDS — evict oldest, clean up their JSON options
        while ( count( $records ) > self::MAX_RECORDS ) {
            $evicted = array_pop( $records );
            delete_option( self::JSON_OPTION_PREFIX . $evicted['job_id'] );
        }
        update_option( self::HISTORY_OPTION, $records );
    }

    public function update_status( string $job_id, string $status, array $extra = [] ): void {
        $records = get_option( self::HISTORY_OPTION, [] );
        foreach ( $records as &$record ) {
            if ( $record['job_id'] === $job_id ) {
                $record['status'] = $status;
                foreach ( $extra as $key => $value ) {
                    $record[ $key ] = $value;
                }
                break;
            }
        }
        unset( $record );
        update_option( self::HISTORY_OPTION, $records );
    }

    public function store_json( string $job_id, string $json ): void {
        update_option( self::JSON_OPTION_PREFIX . $job_id, $json );
    }

    public function get_json( string $job_id ): string {
        return (string) get_option( self::JSON_OPTION_PREFIX . $job_id, '' );
    }

    public function get_all(): array {
        return get_option( self::HISTORY_OPTION, [] );
    }

    public function delete_all(): int {
        $records = get_option( self::HISTORY_OPTION, [] );
        $count   = 0;
        foreach ( $records as $record ) {
            if ( ! empty( $record['job_id'] ) && is_string( $record['job_id'] ) ) {
                delete_option( self::JSON_OPTION_PREFIX . $record['job_id'] );
            }
            $count++;
        }
        delete_option( self::HISTORY_OPTION );
        return $count;
    }
}

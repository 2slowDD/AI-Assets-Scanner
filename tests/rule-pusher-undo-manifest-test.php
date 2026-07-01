<?php

namespace {
    define( 'ABSPATH', __DIR__ );

    function is_plugin_active( $plugin ) {
        return 'code-unloader/code-unloader.php' === $plugin;
    }

    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }

    if ( ! function_exists( 'get_option' ) ) {
        $GLOBALS['aias_test_options'] = array();

        function get_option( string $name, $default = false ) {
            return $GLOBALS['aias_test_options'][ $name ] ?? $default;
        }
    }

    if ( ! function_exists( 'update_option' ) ) {
        function update_option( string $name, $value, bool $autoload = null ): bool {
            $GLOBALS['aias_test_options'][ $name ] = $value;
            return true;
        }
    }

    if ( ! function_exists( 'delete_option' ) ) {
        function delete_option( string $name ): bool {
            unset( $GLOBALS['aias_test_options'][ $name ] );
            return true;
        }
    }

    if ( ! function_exists( 'sanitize_text_field' ) ) {
        function sanitize_text_field( $value ): string {
            return trim( (string) $value );
        }
    }

    if ( ! function_exists( 'absint' ) ) {
        function absint( $maybeint ): int {
            return max( 0, abs( (int) $maybeint ) );
        }
    }

    class WP_Error {
        private string $message;

        public function __construct( $code = '', $message = '' ) {
            $this->message = (string) $message;
        }

        public function get_error_message(): string {
            return $this->message;
        }
    }
}

namespace CUScanner\Scanner {
    class SnapshotManager {
        public function __construct( private string $repo ) {}
        public function has_active_rules(): bool { return false; }
        public function snapshot(): true|\WP_Error { return true; }
        public function rollback(): void {}
        public function commit(): void {}
    }

    class GroupVersionManager {
        public function __construct( private string $repo ) {}
        public function bump_scanner_groups(): true|\WP_Error { return true; }
        public function rollback(): void {}
    }
}

namespace {
    require_once __DIR__ . '/../includes/scanner/class-rule-pusher.php';
    require_once __DIR__ . '/../includes/scanner/class-last-push-sync-undo.php';

    use CUScanner\Scanner\LastPushSyncUndo;
    use CUScanner\Scanner\RulePusher;

    class FakeRuleRepository {
        private static array $groups = [];
        private static array $rules = [];
        private static array $delete_failures = [];
        private static int $next_group_id = 10;
        private static int $next_rule_id = 100;

        public static function reset(): void {
            self::$groups = [];
            self::$rules = [];
            self::$delete_failures = [];
            self::$next_group_id = 10;
            self::$next_rule_id = 100;
        }

        public static function create_group( string $name, string $description ) {
            $id = self::$next_group_id++;
            self::$groups[ $id ] = (object) [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'enabled' => 1,
            ];
            return $id;
        }

        public static function update_group( int $id, array $data ): bool {
            if ( ! isset( self::$groups[ $id ] ) ) {
                return false;
            }

            foreach ( $data as $key => $value ) {
                self::$groups[ $id ]->{$key} = $value;
            }

            return true;
        }

        public static function get_all_groups(): array {
            return array_values( self::$groups );
        }

        public static function get_group( int $id ): ?object {
            return self::$groups[ $id ] ?? null;
        }

        public static function create_rule( array $data ) {
            $id = self::$next_rule_id++;
            self::$rules[ $id ] = (object) array_merge( $data, [ 'id' => $id ] );
            return $id;
        }

        public static function get_rule( int $id ): ?object {
            return self::$rules[ $id ] ?? null;
        }

        public static function fail_delete( int $id ): void {
            self::$delete_failures[ $id ] = true;
        }

        public static function delete_rule( int $id ): bool {
            if ( isset( self::$delete_failures[ $id ] ) ) {
                return false;
            }

            if ( ! isset( self::$rules[ $id ] ) ) {
                return false;
            }

            unset( self::$rules[ $id ] );
            return true;
        }

        public static function find_duplicate( array $payload ): ?object {
            foreach ( self::$rules as $rule ) {
                if (
                    $rule->url_pattern === $payload['url_pattern']
                    && $rule->asset_handle === $payload['asset_handle']
                    && $rule->group_id === $payload['group_id']
                    && $rule->asset_type === $payload['asset_type']
                    && $rule->match_type === $payload['match_type']
                    && $rule->device_type === $payload['device_type']
                    && $rule->source_label === $payload['source_label']
                ) {
                    return $rule;
                }
            }

            return null;
        }
    }

    function assert_same( $expected, $actual, string $message ): void {
        if ( $expected !== $actual ) {
            throw new \RuntimeException(
                $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
            );
        }
    }

    function sample_cu_json(): array {
        return [
            'groups' => [
                [
                    'id' => 1,
                    'name' => 'AA Scanner - Safe',
                    'description' => 'Safe rules',
                ],
                [
                    'id' => 2,
                    'name' => 'AA Scanner - Aggressive',
                    'description' => 'Aggressive rules',
                ],
            ],
            'rules' => [
                [
                    'group_id' => 1,
                    'url_pattern' => '/',
                    'asset_handle' => 'theme-safe',
                    'asset_type' => 'css',
                    'match_type' => 'exact',
                    'device_type' => 'all',
                    'source_label' => 'AA Scanner',
                ],
                [
                    'group_id' => 2,
                    'url_pattern' => '/',
                    'asset_handle' => 'theme-aggressive',
                    'asset_type' => 'js',
                    'match_type' => 'exact',
                    'device_type' => 'all',
                    'source_label' => 'AA Scanner',
                ],
            ],
        ];
    }

    FakeRuleRepository::reset();

    $pusher = new RulePusher( FakeRuleRepository::class );
    $push = $pusher->push( sample_cu_json() );

    assert_same( FakeRuleRepository::class, $pusher->repository_class(), 'repository_class exposes injected repository' );
    assert_same( [ 100, 101 ], $push['created_rule_ids'] ?? null, 'push returns created rule ids' );
    assert_same( [ 10, 11 ], $push['group_ids'] ?? null, 'push returns group ids' );
    assert_same( [ 10, 11 ], $push['created_group_ids'] ?? null, 'push marks fresh groups as created' );

    FakeRuleRepository::reset();
    FakeRuleRepository::create_group( 'AA Scanner - Safe', 'Safe rules' );
    FakeRuleRepository::create_group( 'AA Scanner - Aggressive', 'Aggressive rules' );
    FakeRuleRepository::create_rule(
        [
            'url_pattern' => '/',
            'asset_handle' => 'theme-safe',
            'group_id' => 10,
            'asset_type' => 'css',
            'match_type' => 'exact',
            'device_type' => 'all',
            'source_label' => 'AA Scanner',
        ]
    );

    $sync = $pusher->sync( sample_cu_json() );

    assert_same( [ 101 ], $sync['created_rule_ids'] ?? null, 'sync excludes already-present duplicate rule from created ids' );
    assert_same( [ 10, 11 ], $sync['group_ids'] ?? null, 'sync returns touched groups' );
    assert_same( [], $sync['created_group_ids'] ?? null, 'sync does not mark existing groups as created' );

    $undo = new LastPushSyncUndo();
    $undo->store_from_summary( 'sync', 'job-123', $sync );

    $state = $undo->state_for_ui();
    assert_same( true, $state['available'], 'state_for_ui marks manifest available' );
    assert_same( 'sync', $state['operation'], 'state_for_ui returns operation' );

    $undo->store_from_summary(
        'sync',
        'job-empty',
        array(
            'created_rule_ids'   => array(),
            'group_ids'          => array(),
            'created_group_ids'  => array(),
            'appended_safe'      => 0,
            'appended_aggressive'=> 0,
            'already_present'    => 2,
            'error_count'        => 0,
            'error_message'      => '',
        )
    );
    assert_same( false, $undo->state_for_ui()['available'], 'empty sync summary clears stale manifest state' );

    $undo->store_from_summary( 'sync', 'job-123', $sync );

    $result = $undo->undo( FakeRuleRepository::class );
    assert_same( 1, $result['deleted_rule_count'], 'undo deletes only recorded created rules' );
    assert_same( 0, $result['skipped_rule_count'], 'undo does not skip existing recorded rules' );
    assert_same( 0, $result['disabled_group_count'], 'undo does not disable pre-existing sync groups' );
    assert_same( false, $undo->state_for_ui()['available'], 'undo clears manifest after success' );

    FakeRuleRepository::reset();
    $sync_for_failed_delete = $pusher->sync( sample_cu_json() );
    $undo->store_from_summary( 'sync', 'job-delete-fail', $sync_for_failed_delete );
    FakeRuleRepository::fail_delete( 100 );
    $failed_undo = $undo->undo( FakeRuleRepository::class );
    assert_same( true, is_wp_error( $failed_undo ), 'undo returns an error when an existing rule cannot be deleted' );
    assert_same( true, $undo->state_for_ui()['available'], 'failed rule delete keeps manifest retryable' );
    assert_same( 1, (int) FakeRuleRepository::get_group( 10 )->enabled, 'failed rule delete does not disable groups before a retry' );

    FakeRuleRepository::reset();
    $sync_created = $pusher->sync( sample_cu_json() );
    $undo->store_from_summary( 'sync', 'job-456', $sync_created );
    $undo_result = $undo->undo( FakeRuleRepository::class );
    assert_same( 2, $undo_result['deleted_rule_count'], 'undo deletes sync-created rules' );
    assert_same( 2, $undo_result['disabled_group_count'], 'undo disables sync-created groups' );
    assert_same( 0, (int) FakeRuleRepository::get_group( 10 )->enabled, 'safe sync-created group disabled' );
    assert_same( 0, (int) FakeRuleRepository::get_group( 11 )->enabled, 'aggressive sync-created group disabled' );

    echo "rule-pusher undo metadata ok\n";
}

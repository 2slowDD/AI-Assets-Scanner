<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-flight check called by the admin scan-trigger JS before POSTing the
 * actual scan job. Returns the list of currently-active Class C optimizers
 * and an estimated scan duration so the JS can decide whether to gate on
 * the consent modal (spec §6.1).
 *
 * Same-site admin endpoint — uses `manage_options` capability, not bearer.
 */
class RestPreflight {
    public const NS    = 'cu-scanner/v1';
    public const ROUTE = '/preflight';

    public static function register_routes(): void {
        \register_rest_route( self::NS, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'handle' ],
            'permission_callback' => [ self::class, 'permission_callback' ],
        ] );
    }

    public static function permission_callback( \WP_REST_Request $request ): bool {
        return (bool) \current_user_can( 'manage_options' );
    }

    /** @return array{class_c_active: array<int, array{slug:string,name:string,warning:string}>, estimated_minutes: int} */
    public static function handle( \WP_REST_Request $request ): array {
        $detector = new PluginDetector();
        $entries  = $detector->detect_typed();

        $class_c = [];
        foreach ( $entries as $entry ) {
            if ( ( $entry['class'] ?? '' ) !== 'C' ) continue;
            $slug = $entry['disable_method'] ?? '';
            if ( $slug === '' ) continue;
            $class_c[] = [
                'slug'    => (string) $slug,
                'name'    => (string) ( $entry['name'] ?? '' ),
                'warning' => (string) ( $entry['warning'] ?? '' ),
            ];
        }

        return [
            'class_c_active'    => $class_c,
            'estimated_minutes' => self::estimate_minutes(),
        ];
    }

    /**
     * Naive estimate. Future spec §6.1 derives this from scan_timeout × URL count;
     * for now, return a constant range placeholder that reflects typical scan time.
     */
    private static function estimate_minutes(): int {
        return 5;
    }
}

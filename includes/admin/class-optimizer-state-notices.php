<?php
namespace CUScanner\Admin;

use CUScanner\Scanner\OptimizerBypassOrchestrator;
use CUScanner\Scanner\OptimizerState;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "scan in progress" admin banner and handles the force-restore
 * admin-post action. Spec §6.2 + §6.3 + §5 manual restore path.
 */
class OptimizerStateNotices {
    public const FORCE_RESTORE_ACTION = 'aias_force_restore';
    public const FORCE_RESTORE_NONCE  = 'aias_force_restore';

    public static function init(): void {
        \add_action( 'admin_notices',                             [ self::class, 'render_banner' ] );
        \add_action( 'admin_post_' . self::FORCE_RESTORE_ACTION, [ self::class, 'handle_force_restore' ] );
    }

    public static function render_banner(): void {
        $state = OptimizerState::load();
        if ( ! $state ) {
            return;
        }

        $slugs = array_keys( $state['snapshots'] ?? [] );
        if ( empty( $slugs ) ) {
            return;
        }

        $url = \add_query_arg(
            '_wpnonce',
            \wp_create_nonce( self::FORCE_RESTORE_NONCE ),
            \admin_url( 'admin-post.php?action=' . self::FORCE_RESTORE_ACTION )
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline sprintf wraps esc_html__() template + esc_html() value; both fragments are escaped before concat. Sniff does not trace into sprintf return.
        printf(
            '<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
            sprintf(
                /* translators: %s: comma-separated list of optimizer plugin slugs */
                \esc_html__( 'Scan in progress — %s temporarily paused. Re-enabled automatically when the scan finishes.', 'AI-Assets-Scanner' ),
                \esc_html( implode( ', ', $slugs ) )
            ),
            \esc_url( $url ),
            \esc_html__( 'Force restore now', 'AI-Assets-Scanner' )
        );
    }

    public static function handle_force_restore(): void {
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_die( 'Insufficient permissions.', 'CU Scanner', [ 'response' => 403 ] );
        }
        \check_admin_referer( self::FORCE_RESTORE_NONCE );

        $state = OptimizerState::load();
        if ( $state ) {
            OptimizerBypassOrchestrator::build_default_orchestrator()
                ->complete_with_loaded_state( $state, 'manual' );
            // Belt-and-braces: ensure state is cleared even if all strategies were no-ops.
            OptimizerState::clear();
        }

        \wp_safe_redirect(
            \add_query_arg(
                'aias_force_restore',
                'done',
                \admin_url( 'admin.php?page=cu-scanner' )
            )
        );
        exit;
    }
}

<?php
namespace CUScanner\Scanner\Strategies;

defined( 'ABSPATH' ) || exit;

/**
 * Class C strategy for SiteGround Optimizer.
 *
 * SG Optimizer stores each setting as its own wp_option (`siteground_optimizer_*`),
 * unlike FlyingPress's single-array shape. disable() writes 0 to each documented
 * option; restore() writes back the snapshot value, or delete_option's keys that
 * were originally absent (null in the snapshot).
 *
 * Spec §4.5 + §3 (Class C taxonomy).
 */
class SgOptimizerBypass extends AbstractOptimizerBypass {
    private const OPTIONS = [
        'siteground_optimizer_combine_css',
        'siteground_optimizer_minify_css',
        'siteground_optimizer_minify_javascript',
        'siteground_optimizer_combine_javascript',
        'siteground_optimizer_optimize_html_render',
        'siteground_optimizer_lazyload_images',
    ];

    public function slug(): string {
        return 'sg_optimizer';
    }

    public function snapshot(): array {
        $snap = [];
        foreach ( self::OPTIONS as $name ) {
            // null sentinel: option absent → restore unsets via delete_option.
            $value = get_option( $name, null );
            $snap[ $name ] = $value;
        }
        return $snap;
    }

    public function disable(): void {
        foreach ( self::OPTIONS as $name ) {
            update_option( $name, 0 );
        }
    }

    public function restore( array $snapshot ): void {
        foreach ( $snapshot as $name => $value ) {
            if ( $value === null ) {
                delete_option( $name );
            } else {
                update_option( $name, $value );
            }
        }
    }
}

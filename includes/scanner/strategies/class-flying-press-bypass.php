<?php
namespace CUScanner\Scanner\Strategies;

defined( 'ABSPATH' ) || exit;

/**
 * Class C strategy for FlyingPress.
 *
 * FlyingPress stores all optimization toggles inside a single option array
 * (`flying_press_settings`). This strategy captures the documented keys,
 * sets each to false during disable, and restores from the snapshot.
 *
 * Keys disabled (per FlyingPress source as of v4.x):
 *   - optimize_css
 *   - lazy_load_css
 *   - remove_unused_css
 *   - minify_css
 *   - minify_js
 *   - defer_js
 *   - lazy_load_iframes
 *
 * The exact key list MUST be re-verified against the shipped FlyingPress
 * source if the upstream plugin renames or restructures `flying_press_settings`.
 */
class FlyingPressBypass extends AbstractOptimizerBypass {
    private const OPTION = 'flying_press_settings';
    private const KEYS   = [
        'optimize_css',
        'lazy_load_css',
        'remove_unused_css',
        'minify_css',
        'minify_js',
        'defer_js',
        'lazy_load_iframes',
    ];

    public function slug(): string {
        return 'flying_press';
    }

    public function snapshot(): array {
        $opts = get_option( self::OPTION, [] );
        if ( ! is_array( $opts ) ) {
            $opts = [];
        }
        $snap = [];
        foreach ( self::KEYS as $k ) {
            $snap[ $k ] = array_key_exists( $k, $opts ) ? $opts[ $k ] : null;
        }
        return $snap;
    }

    public function disable(): void {
        $opts = get_option( self::OPTION, [] );
        if ( ! is_array( $opts ) ) {
            $opts = [];
        }
        foreach ( self::KEYS as $k ) {
            $opts[ $k ] = false;
        }
        update_option( self::OPTION, $opts );
    }

    public function restore( array $snapshot ): void {
        $opts = get_option( self::OPTION, [] );
        if ( ! is_array( $opts ) ) {
            $opts = [];
        }
        foreach ( $snapshot as $key => $value ) {
            if ( $value === null ) {
                unset( $opts[ $key ] );
            } else {
                $opts[ $key ] = $value;
            }
        }
        update_option( self::OPTION, $opts );
    }
}

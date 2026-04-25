<?php
namespace CUScanner\Scanner\Strategies;

defined( 'ABSPATH' ) || exit;

/**
 * Class C strategy for Hummingbird's CSS/JS minify module.
 *
 * Only flips `wphb_settings.minify.enabled`. Hummingbird's page-cache module
 * is Class B (handled by the existing cu_scan_token query string) and is not
 * touched by this strategy. The detector at runtime decides whether
 * Hummingbird is Class B (no consent) or Class C (this strategy fires) based
 * on the `minify.enabled` flag.
 *
 * Spec §4.5 + §4.2.1 (module-level disambiguation).
 */
class HummingbirdBypass extends AbstractOptimizerBypass {
    private const OPTION = 'wphb_settings';

    public function slug(): string {
        return 'hummingbird';
    }

    public function snapshot(): array {
        $opts = get_option( self::OPTION, [] );
        if ( ! is_array( $opts ) ) {
            $opts = [];
        }
        $minify = ( isset( $opts['minify'] ) && is_array( $opts['minify'] ) )
            ? $opts['minify']
            : [];
        return [
            'minify_enabled' => array_key_exists( 'enabled', $minify ) ? $minify['enabled'] : null,
        ];
    }

    public function disable(): void {
        $opts = get_option( self::OPTION, [] );
        if ( ! is_array( $opts ) ) {
            $opts = [];
        }
        if ( ! isset( $opts['minify'] ) || ! is_array( $opts['minify'] ) ) {
            $opts['minify'] = [];
        }
        $opts['minify']['enabled'] = false;
        update_option( self::OPTION, $opts );
    }

    public function restore( array $snapshot ): void {
        $opts = get_option( self::OPTION, [] );
        if ( ! is_array( $opts ) ) {
            $opts = [];
        }
        if ( ! isset( $opts['minify'] ) || ! is_array( $opts['minify'] ) ) {
            $opts['minify'] = [];
        }
        if ( array_key_exists( 'minify_enabled', $snapshot ) && $snapshot['minify_enabled'] === null ) {
            unset( $opts['minify']['enabled'] );
        } elseif ( array_key_exists( 'minify_enabled', $snapshot ) ) {
            $opts['minify']['enabled'] = $snapshot['minify_enabled'];
        }
        update_option( self::OPTION, $opts );
    }
}

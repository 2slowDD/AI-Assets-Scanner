<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

class PluginDetector {
    // slug => [label, params[]]
    private const AUTO_BYPASS = [
        'wp-rocket/wp-rocket.php'     => [ 'WP Rocket',   [ 'nowprocket' ] ],
        'autoptimize/autoptimize.php' => [ 'Autoptimize', [ 'ao_noptimize=1' ] ],
    ];

    private const SOFT_BLOCK = [
        'nitropack/nitropack.php'                                    => [ 'NitroPack',       'Delays JS loading and strips CSS server-side. Disable optimization features before scanning.' ],
        'litespeed-cache/litespeed-cache.php'                        => [ 'LiteSpeed Cache', 'CSS/JS optimization active. Disable minification in LiteSpeed settings before scanning.' ],
        'hummingbird-performance/wp-hummingbird.php'                 => [ 'Hummingbird',     'Asset optimization active. Disable before scanning.' ],
        'w3-total-cache/w3-total-cache.php'                          => [ 'W3 Total Cache',  'Minification may be active. Disable JS/CSS minification before scanning.' ],
        'swift-performance-lite/swift-performance-lite.php'          => [ 'Swift Performance', 'Asset optimization active. Disable before scanning.' ],
        'flying-scripts/flying-scripts.php'                          => [ 'Flying Scripts',  'Delays network fetch of scripts until user interaction — passive scan will miss those scripts, producing incorrect Safe rules.' ],
    ];

    private const SOFT_WARN = [
        'perfmatters/perfmatters.php'             => [ 'Perfmatters',        'May have dequeued assets already. Scan results may be incomplete.' ],
        'asset-cleanup/asset-cleanup.php'         => [ 'AssetsCleanUp',      'May have dequeued assets already. Scan results may be incomplete.' ],
        'scripts-to-footer/scripts-to-footer.php' => [ 'Scripts to Footer',  'Moves scripts — scan still works but results may be incomplete.' ],
        'flying-press/flying-press.php'           => [ 'Flying Press',       'May have dequeued assets. Scan results may be incomplete.' ],
    ];

    private const SECURITY_WARN = [
        'wordfence/wordfence.php' => [
            'Wordfence',
            'Rate limiting or WAF may block the scanner. Temporarily disable rate limiting before scanning.',
            null,
        ],
        'wordfence-login-security/wordfence-login-security.php' => [
            'Wordfence Login Security',
            'Rate limiting may block the scanner. Temporarily disable before scanning.',
            null,
        ],
        'cloudflare/cloudflare.php' => [
            'Cloudflare',
            'Bot Fight Mode or WAF rules may block the scanner. Set up a permanent bypass rule, or temporarily disable bot protection before scanning.',
            'cu-cloudflare-waf-bypass',
        ],
    ];

    private const CU_PLUGIN      = 'code-unloader/code-unloader.php';
    private const CU_MIN_VERSION = '1.3.9';

    /**
     * Per-spec §3 optimizer matrix. Keyed by plugin file path.
     * Hummingbird's class/disable_method are null at the constant level — set
     * at runtime by detect_typed() based on `wphb_settings.minify.enabled`.
     */
    private const OPTIMIZERS = [
        'wp-rocket/wp-rocket.php' => [
            'name' => 'WP Rocket', 'class' => 'A', 'bypass_query' => 'nowprocket',
            'disable_method' => null, 'warning' => null,
        ],
        'perfmatters/perfmatters.php' => [
            'name' => 'Perfmatters', 'class' => 'A', 'bypass_query' => 'perfmattersoff',
            'disable_method' => null, 'warning' => null,
        ],
        'autoptimize/autoptimize.php' => [
            'name' => 'Autoptimize', 'class' => 'A', 'bypass_query' => 'ao_noptimize=1',
            'disable_method' => null, 'warning' => null,
        ],
        'nitropack/main.php' => [
            'name' => 'NitroPack', 'class' => 'A', 'bypass_query' => 'nonitro',
            'disable_method' => null, 'warning' => null,
        ],
        'asset-cleanup/asset-cleanup.php' => [
            'name' => 'Asset CleanUp', 'class' => 'A', 'bypass_query' => 'wpacu_no_load',
            'disable_method' => null, 'warning' => null,
        ],
        'litespeed-cache/litespeed-cache.php' => [
            'name' => 'LiteSpeed Cache', 'class' => 'A_star',
            'bypass_query' => 'LSCWP_CTRL=before_optm',
            'disable_method' => null, 'warning' => null,
        ],
        'wp-fastest-cache/wpFastestCache.php' => [
            'name' => 'WP Fastest Cache', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
        ],
        'w3-total-cache/w3-total-cache.php' => [
            'name' => 'W3 Total Cache', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
        ],
        'breeze/breeze.php' => [
            'name' => 'Breeze', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
        ],
        'cache-enabler/cache-enabler.php' => [
            'name' => 'Cache Enabler', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
        ],
        'swift-performance-lite/performance.php' => [
            'name' => 'Swift Performance', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
        ],
        'hummingbird-performance/wp-hummingbird.php' => [
            'name' => 'Hummingbird', 'class' => null, 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
        ],
        'flying-press/flying-press.php' => [
            'name' => 'FlyingPress', 'class' => 'C', 'bypass_query' => null,
            'disable_method' => 'flying_press',
            'warning' => 'CSS/JS optimization will be paused for the duration of this scan and re-enabled automatically afterward.',
        ],
        'sg-cachepress/sg-cachepress.php' => [
            'name' => 'SiteGround Optimizer', 'class' => 'C', 'bypass_query' => null,
            'disable_method' => 'sg_optimizer',
            'warning' => 'CSS/JS optimization will be paused for the duration of this scan and re-enabled automatically afterward.',
        ],
    ];

    public function detect(): array {
        $result = [ 'auto_bypass' => [], 'soft_block' => [], 'soft_warn' => [], 'security_warn' => [], 'cu_missing' => false ];

        foreach ( self::AUTO_BYPASS as $file => [ $label, $params ] ) {
            if ( is_plugin_active( $file ) ) {
                $slug = explode( '/', $file )[0];
                $result['auto_bypass'][ $slug ] = $params;
            }
        }
        foreach ( self::SOFT_BLOCK as $file => [ $label, $reason ] ) {
            if ( is_plugin_active( $file ) ) {
                $result['soft_block'][ $label ] = $reason;
            }
        }
        foreach ( self::SOFT_WARN as $file => [ $label, $reason ] ) {
            if ( is_plugin_active( $file ) ) {
                $result['soft_warn'][ $label ] = $reason;
            }
        }

        foreach ( self::SECURITY_WARN as $file => [ $label, $reason, $anchor ] ) {
            if ( is_plugin_active( $file ) ) {
                $base = admin_url( 'admin.php?page=cu-scanner-settings' );
                $result['security_warn'][ $label ] = [
                    'reason'       => $reason,
                    'settings_url' => $anchor ? $base . '#' . $anchor : $base,
                ];
            }
        }

        // Code Unloader: flag as missing, auto-bypass if >= 1.3.9, soft-block if older
        if ( ! is_plugin_active( self::CU_PLUGIN ) ) {
            $result['cu_missing'] = true;
        } else {
            $data    = get_plugin_data( \WP_PLUGIN_DIR . '/' . self::CU_PLUGIN );
            $version = $data['Version'] ?? '0';
            if ( version_compare( $version, self::CU_MIN_VERSION, '>=' ) ) {
                $result['auto_bypass']['code-unloader'] = [ 'nowpcu' ];
            } else {
                $result['soft_block']['Code Unloader'] = "Version {$version} detected. Upgrade to v" . self::CU_MIN_VERSION . "+ for automatic bypass, or disable Code Unloader before scanning.";
            }
        }

        return $result;
    }

    /**
     * Returns the spec §3 typed array shape: per-plugin entries for every
     * currently-active optimizer. Distinct from detect() — this method is
     * driven by the OPTIMIZERS const, not the legacy AUTO_BYPASS/SOFT_BLOCK/
     * SOFT_WARN classification.
     *
     * @return array<string, array{name:string,class:string,bypass_query:?string,disable_method:?string,warning:?string}>
     */
    public function detect_typed(): array {
        $out = [];
        foreach ( self::OPTIMIZERS as $file => $base ) {
            if ( ! is_plugin_active( $file ) ) {
                continue;
            }
            // Hummingbird runtime module probe (§4.2.1)
            if ( $file === 'hummingbird-performance/wp-hummingbird.php' ) {
                $opts          = get_option( 'wphb_settings', [] );
                $minify        = is_array( $opts ) && isset( $opts['minify'] ) && is_array( $opts['minify'] )
                    ? $opts['minify']
                    : [];
                $minify_active = ! empty( $minify['enabled'] );
                $base['class']          = $minify_active ? 'C' : 'B';
                $base['disable_method'] = $minify_active ? 'hummingbird' : null;
                $base['warning']        = $minify_active
                    ? 'CSS/JS minification will be paused for the duration of this scan and re-enabled automatically afterward.'
                    : null;
            }
            $out[ $file ] = $base;
        }
        return $out;
    }
}

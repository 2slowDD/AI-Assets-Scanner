<?php
namespace CUScanner\Scanner;

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
}

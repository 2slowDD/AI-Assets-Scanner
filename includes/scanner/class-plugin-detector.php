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
            'target_headers' => ['x-wp-rocket-cache'],
            'target_body_markers' => ['This website is like a Rocket'],
        ],
        'perfmatters/perfmatters.php' => [
            'name' => 'Perfmatters', 'class' => 'A', 'bypass_query' => 'perfmattersoff',
            'disable_method' => null, 'warning' => null,
            'target_headers' => [],
            'target_body_markers' => ['/wp-content/plugins/perfmatters/'],
        ],
        'autoptimize/autoptimize.php' => [
            'name' => 'Autoptimize', 'class' => 'A', 'bypass_query' => 'ao_noptimize=1',
            'disable_method' => null, 'warning' => null,
            'target_headers' => [],
            'target_body_markers' => ['/wp-content/cache/autoptimize/', '/* autoptimize'],
        ],
        'nitropack/main.php' => [
            'name' => 'NitroPack', 'class' => 'A', 'bypass_query' => 'nonitro',
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-nitro-cache'],
            'target_body_markers' => ['nitrocdn.com', 'data-nitro'],
        ],
        'asset-cleanup/asset-cleanup.php' => [
            'name' => 'Asset CleanUp', 'class' => 'A', 'bypass_query' => 'wpacu_no_load',
            'disable_method' => null, 'warning' => null,
            'target_headers' => [],
            'target_body_markers' => ['/wp-content/plugins/wp-asset-clean-up/', 'data-wpacu'],
        ],
        'litespeed-cache/litespeed-cache.php' => [
            'name' => 'LiteSpeed Cache', 'class' => 'A_star',
            'bypass_query' => 'LSCWP_CTRL=before_optm',
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-litespeed-cache'],
            'target_body_markers' => ['Page generated by LiteSpeed', '/wp-content/cache/litespeed/'],
        ],
        'wp-fastest-cache/wpFastestCache.php' => [
            'name' => 'WP Fastest Cache', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-cache: wpfc-'],
            'target_body_markers' => ['WP Fastest Cache file was created'],
        ],
        'w3-total-cache/w3-total-cache.php' => [
            'name' => 'W3 Total Cache', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-w3tc-cached-by', 'x-w3tc-page-cache'],
            'target_body_markers' => ['Performance optimized by W3 Total Cache'],
        ],
        'breeze/breeze.php' => [
            'name' => 'Breeze', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-cache-handler: breeze'],
            'target_body_markers' => ['Cache served by breeze'],
        ],
        'cache-enabler/cache-enabler.php' => [
            'name' => 'Cache Enabler', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-cache-handler: cache-enabler-engine'],
            'target_body_markers' => ['Cache Enabler by KeyCDN'],
        ],
        'swift-performance-lite/performance.php' => [
            'name' => 'Swift Performance', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
            'target_headers' => [],
            'target_body_markers' => ['Cached by Swift Performance', 'swift-performance'],
        ],
        'hummingbird-performance/wp-hummingbird.php' => [
            'name' => 'Hummingbird', 'class' => null, 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
            'target_headers' => [],
            'target_body_markers' => ['/wp-content/cache/hummingbird/', 'Hummingbird-Performance'],
        ],
        // FlyingPress reclassified C → A per spec §5.2 (changelog v2.3.0 ?no_optimize).
        // Strategy file + StrategyFactory match arm deleted in Phase 3 per N6 YAGNI decision.
        'flying-press/flying-press.php' => [
            'name' => 'FlyingPress', 'class' => 'A', 'bypass_query' => 'no_optimize',
            'disable_method' => null, 'warning' => null,
            'target_headers' => [],
            'target_body_markers' => ['Optimized by FlyingPress', '/wp-content/plugins/flying-press/'],
        ],
        'sg-cachepress/sg-cachepress.php' => [
            'name' => 'SiteGround Optimizer', 'class' => 'C', 'bypass_query' => null,
            'disable_method' => 'sg_optimizer',
            'warning' => 'CSS/JS optimization will be paused for the duration of this scan and re-enabled automatically afterward.',
            'target_headers' => ['x-powered-by: siteground'],
            'target_body_markers' => ['Optimized by SG Optimizer'],
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
     * @return array<string, array{name:string,class:?string,bypass_query:?string,disable_method:?string,warning:?string,target_headers:string[],target_body_markers:string[]}>
     */
    /**
     * Extract bypass-key suffixes from typed-detector entries.
     * Only Class A and A_star contribute; B and C return no suffix (B is QS-naive,
     * C requires plugin-side disable orchestrator).
     *
     * @param array<string, array> $typed_entries Output of detect_typed().
     * @return string[] Bypass keys (bare flag or `key=value`), in detector iteration order.
     */
    public static function build_bypass_suffixes( array $typed_entries ): array {
        $out = [];
        foreach ( $typed_entries as $entry ) {
            $class = $entry['class'] ?? null;
            $key   = $entry['bypass_query'] ?? null;
            if ( in_array( $class, [ 'A', 'A_star' ], true ) && is_string( $key ) && $key !== '' ) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Map a plugin file path to the optimizer enum used in event fields.
     *
     * @param string $file Plugin file path (e.g. 'wp-rocket/wp-rocket.php').
     * @return string Enum string, or 'unknown' for unmapped paths.
     */
    public static function plugin_file_to_enum( string $file ): string {
        static $map = [
            'wp-rocket/wp-rocket.php'                    => 'rocket',
            'perfmatters/perfmatters.php'                => 'perfmatters',
            'litespeed-cache/litespeed-cache.php'        => 'litespeed',
            'autoptimize/autoptimize.php'                => 'autoptimize',
            'nitropack/main.php'                         => 'nitropack',
            'asset-cleanup/asset-cleanup.php'            => 'asset_cleanup',
            'wp-fastest-cache/wpFastestCache.php'        => 'wp_fastest_cache',
            'w3-total-cache/w3-total-cache.php'          => 'w3tc',
            'breeze/breeze.php'                          => 'breeze',
            'cache-enabler/cache-enabler.php'            => 'cache_enabler',
            'swift-performance-lite/performance.php'     => 'swift',
            'hummingbird-performance/wp-hummingbird.php' => 'hummingbird',
            'flying-press/flying-press.php'              => 'flying_press',
            'sg-cachepress/sg-cachepress.php'            => 'sg_optimizer',
        ];
        return $map[ $file ] ?? 'unknown';
    }

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

    /**
     * Match any of $patterns against any header value (case-insensitive substring).
     * Used by target-probe outcome classification.
     *
     * @param array $headers Headers as returned by wp_remote_retrieve_headers (assoc array).
     * @param array $patterns Patterns to match (case-insensitive substring).
     * @return bool true if ANY pattern matches ANY header value.
     */
    private static function header_match( array $headers, array $patterns ): bool {
        if ( empty( $patterns ) ) return false;
        // Flatten header values to a single lowercase string for substring search.
        $haystack = '';
        foreach ( $headers as $name => $val ) {
            if ( is_array( $val ) ) $val = implode( ', ', $val );
            $haystack .= strtolower( (string) $name ) . ': ' . strtolower( (string) $val ) . "\n";
        }
        foreach ( $patterns as $pat ) {
            if ( strpos( $haystack, strtolower( (string) $pat ) ) !== false ) return true;
        }
        return false;
    }

    /**
     * Match any of $patterns against the body (case-insensitive substring on first 32KB).
     * 32KB cap is per spec §5.5 + §8 row 18 — bounds CPU regardless of server's Range support.
     *
     * @param string $body Body string.
     * @param array $patterns Patterns to match (case-insensitive substring).
     * @return bool true if ANY pattern matches.
     */
    private static function body_match( string $body, array $patterns ): bool {
        if ( empty( $patterns ) ) return false;
        $haystack = strtolower( substr( $body, 0, 32768 ) );
        foreach ( $patterns as $pat ) {
            if ( strpos( $haystack, strtolower( (string) $pat ) ) !== false ) return true;
        }
        return false;
    }

    /**
     * Classify probe outcome per spec §5.4 decision tree.
     * Precedence: probe_failed > non_wordpress > optimizer classification.
     *
     * @param bool  $probe_failed True if both probe URLs returned WP_Error / 5xx / 403 / 429 / timeout.
     * @param bool  $is_wordpress True if any WP signal was detected on either probe URL.
     * @param array $detected     Array of detected entries (each with 'class' key).
     * @return string Outcome class: probe_failed | non_wordpress | class_a_clean | class_bc_only | hybrid_a_plus_bc | no_clue.
     */
    private static function classify_outcome( bool $probe_failed, bool $is_wordpress, array $detected ): string {
        if ( $probe_failed ) return 'probe_failed';
        if ( ! $is_wordpress ) return 'non_wordpress';

        $classes_seen = [];
        foreach ( $detected as $d ) {
            $c = $d['class'] ?? null;
            if ( $c ) $classes_seen[ $c ] = true;
        }
        $has_class_a  = isset( $classes_seen['A'] ) || isset( $classes_seen['A_star'] );
        $has_class_bc = isset( $classes_seen['B'] ) || isset( $classes_seen['C'] );

        if ( $has_class_a && $has_class_bc ) return 'hybrid_a_plus_bc';
        if ( $has_class_a )                   return 'class_a_clean';
        if ( $has_class_bc )                  return 'class_bc_only';
        return 'no_clue';
    }

    // --- Test seams (private-method exposure for unit testing) ---
    public static function __test_header_match( array $headers, array $patterns ): bool {
        return self::header_match( $headers, $patterns );
    }
    public static function __test_body_match( string $body, array $patterns ): bool {
        return self::body_match( $body, $patterns );
    }
    public static function __test_classify_outcome( bool $probe_failed, bool $is_wordpress, array $detected ): string {
        return self::classify_outcome( $probe_failed, $is_wordpress, $detected );
    }
}

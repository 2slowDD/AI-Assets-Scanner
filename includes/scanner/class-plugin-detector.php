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
     * §5.5 + §8 row 18 — CPU-bound cap on body-scan haystack.
     * Bounds substring-scan cost regardless of whether the upstream server
     * honored the probe's Range: bytes=0-32767 request.
     */
    private const BODY_SCAN_MAX_BYTES = 32768;

    /**
     * Rev-2 C1 — injectable-override seams for detector dependencies.
     * Default null = production fall-through (real WPMU_PLUGIN_DIR / PANTHEON_ENVIRONMENT).
     * Tests swap via __test_set_*_override() to avoid PHP's define-once semantics.
     */
    private static $mu_plugin_dir_override = null;
    private static $pantheon_env_override  = null;

    /**
     * AC-N2-SSRF (i) — scheme allowlist for probe URLs.
     * Rejects file://, javascript:, ftp://, gopher://, etc. before any
     * wp_remote_get call.
     */
    private const ALLOWED_SCHEMES = [ 'http', 'https' ];

    /**
     * AC-T2-6 hoist-preservation instrumentation. Tests reset + read this counter
     * to verify extract_non_text_zones is invoked at most once per single_probe_attempt.
     * Production code does not depend on this value.
     */
    public static $extract_call_count = 0;

    /**
     * Per-spec §3 optimizer matrix. Keyed by plugin file path.
     * Hummingbird's class/disable_method are null at the constant level — set
     * at runtime by detect_typed() based on `wphb_settings.minify.enabled`.
     */
    private const OPTIMIZERS = [
        'wp-rocket/wp-rocket.php' => [
            'name' => 'WP Rocket', 'class' => 'A', 'bypass_query' => 'nowprocket',
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-wp-rocket-cache', 'x-rocket-nginx-bypass'],
            'target_body_markers' => ['This website is like a Rocket'],
            'target_body_pattern' => '/\bwp[- _]?rocket\b/i',
        ],
        'perfmatters/perfmatters.php' => [
            'name' => 'Perfmatters', 'class' => 'A', 'bypass_query' => 'perfmattersoff',
            'disable_method' => null, 'warning' => null,
            'target_headers' => [],
            'target_body_markers' => ['/wp-content/plugins/perfmatters/'],
            'target_body_pattern' => '/\bperfmatters\b/i',
        ],
        'autoptimize/autoptimize.php' => [
            'name' => 'Autoptimize', 'class' => 'A', 'bypass_query' => 'ao_noptimize=1',
            'disable_method' => null, 'warning' => null,
            'target_headers' => [],
            'target_body_markers' => ['/wp-content/cache/autoptimize/', '/* autoptimize'],
            'target_body_pattern' => '/\bautoptimize\b/i',
        ],
        'nitropack/main.php' => [
            'name' => 'NitroPack', 'class' => 'A', 'bypass_query' => 'nonitro',
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-nitro-cache', 'x-nitro-cache-from', 'x-nitro-rev'],
            'target_body_markers' => ['nitrocdn.com', 'data-nitro'],
            'target_body_pattern' => '/\bnitro(?:pack|cdn)\b/i',
        ],
        'asset-cleanup/asset-cleanup.php' => [
            'name' => 'Asset CleanUp', 'class' => 'A', 'bypass_query' => 'wpacu_no_load',
            'disable_method' => null, 'warning' => null,
            'target_headers' => [],
            'target_body_markers' => ['/wp-content/plugins/wp-asset-clean-up/', 'data-wpacu'],
            'target_body_pattern' => '/\bwpacu\b|\basset[- _]?clean[- _]?up\b/i',
        ],
        'litespeed-cache/litespeed-cache.php' => [
            'name' => 'LiteSpeed Cache', 'class' => 'A_star',
            'bypass_query' => 'LSCWP_CTRL=before_optm',
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-litespeed-cache', 'x-litespeed-cache-control'],
            'target_body_markers' => ['Page generated by LiteSpeed', '/wp-content/cache/litespeed/'],
            'target_body_pattern' => '/\blitespeed[- _]?cache\b/i',
        ],
        'wp-fastest-cache/wpFastestCache.php' => [
            'name' => 'WP Fastest Cache', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
            'target_headers' => [],
            'target_body_markers' => ['WP Fastest Cache file was created'],
            'target_body_pattern' => '/\bwp[- _]?fastest[- _]?cache\b/i',
        ],
        'w3-total-cache/w3-total-cache.php' => [
            'name' => 'W3 Total Cache', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-w3tc-cached-by', 'x-w3tc-page-cache', 'x-w3tc-cdn', 'x-powered-by: w3 total cache'],
            'target_body_markers' => ['Performance optimized by W3 Total Cache'],
            'target_body_pattern' => '/\b(?:w3tc|w3[- _]?total[- _]?cache)\b/i',
        ],
        'breeze/breeze.php' => [
            'name' => 'Breeze', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-cache-handler: breeze', 'x-breeze-cache-write', 'x-breeze-cache', 'x-breeze-circuit-breaker'],
            'target_body_markers' => ['Cache served by breeze'],
            // Phrase-anchored — bare 'breeze' is too generic (common English word). §5.5 note.
            'target_body_pattern' => '/\bcache[- _]served[- _]by[- _]breeze\b|\bbreeze[- _]cache\b/i',
        ],
        'cache-enabler/cache-enabler.php' => [
            'name' => 'Cache Enabler', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-cache-handler: cache-enabler-engine'],
            'target_body_markers' => ['Cache Enabler by KeyCDN'],
            'target_body_pattern' => '/\bcache[- _]?enabler\b/i',
        ],
        'swift-performance-lite/performance.php' => [
            'name' => 'Swift Performance', 'class' => 'B', 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
            // NOTE: 'swift3: ' carries an INTENTIONAL trailing space — anchors on the header-name
            // boundary in header_match's "name: value\n" haystack. DO NOT let an editor auto-trim it;
            // 'swift3:' alone could false-positive on substrings inside arbitrary value fields.
            'target_headers' => ['swift3: ', 'x-cache-status: identical', 'x-cache-status: changed', 'x-cache-status: not-modified'],
            'target_body_markers' => ['Cached by Swift Performance', 'swift-performance'],
            'target_body_pattern' => '/\bswift[- _]?performance\b/i',
        ],
        'hummingbird-performance/wp-hummingbird.php' => [
            'name' => 'Hummingbird', 'class' => null, 'bypass_query' => null,
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['hummingbird-cache'],
            'target_body_markers' => ['/wp-content/cache/hummingbird/', 'Hummingbird-Performance'],
            'target_body_pattern' => '/\bhummingbird(?:[- _]performance)?\b/i',
        ],
        // FlyingPress reclassified C → A per spec §5.2 (changelog v2.3.0 ?no_optimize).
        // Strategy file + StrategyFactory match arm deleted in Phase 3 per N6 YAGNI decision.
        'flying-press/flying-press.php' => [
            'name' => 'FlyingPress', 'class' => 'A', 'bypass_query' => 'no_optimize',
            'disable_method' => null, 'warning' => null,
            'target_headers' => ['x-flying-press-cache', 'x-flying-press-source'],
            // 'Optimized by FlyingPress' kept for legacy plugin versions; 'Powered by FlyingPress' is the
            // current v2.x footer comment that triggered the 1.4.0 diagnostic. Both literals serve as
            // defense-in-depth alongside the target_body_pattern regex below.
            'target_body_markers' => ['Powered by FlyingPress', 'Optimized by FlyingPress', '/wp-content/plugins/flying-press/'],
            'target_body_pattern' => '/\bflying[- _]?press\b/i',
        ],
        'sg-cachepress/sg-cachepress.php' => [
            'name' => 'SiteGround Optimizer', 'class' => 'C', 'bypass_query' => null,
            'disable_method' => 'sg_optimizer',
            'warning' => 'CSS/JS optimization will be paused for the duration of this scan and re-enabled automatically afterward.',
            'target_headers' => ['sg-f-cache', 'x-powered-by: siteground'],
            'target_body_markers' => ['Optimized by SG Optimizer'],
            'target_body_pattern' => '/\b(?:sg|siteground)[- _]?optimizer\b/i',
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

    /**
     * Returns the spec §3 typed array shape: per-plugin entries for every
     * currently-active optimizer. Distinct from detect() — this method is
     * driven by the OPTIMIZERS const, not the legacy AUTO_BYPASS/SOFT_BLOCK/
     * SOFT_WARN classification.
     *
     * @return array<string, array{name:string,class:?string,bypass_query:?string,disable_method:?string,warning:?string,target_headers:string[],target_body_markers:string[]}>
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
        // NOTE: patterns must not span lines; \n separator is for substring search only.
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
     * Match any of $patterns against the body (case-insensitive substring).
     *
     * Pass 1 ($use_range=true): scans first BODY_SCAN_MAX_BYTES (32KB) of body.
     * Pass 2 ($use_range=false): scans the FULL body (already capped at 2MB by limit_response_size
     * in single_probe_attempt's wp_remote_get args).
     *
     * T3 widening (spec §6.3): drops the prior $scan_tail_only / $tail_only param. Pass 2 now sees
     * full body instead of just the last 8KB tail — closes the dead zone between 32KB head and 8KB
     * tail for plugins whose markers sit in the body middle (e.g. flying-press script tags at ~byte
     * 125K on flyingpress.com).
     *
     * @param string $body
     * @param array  $patterns Case-insensitive substring patterns.
     * @param bool   $use_range True for Pass 1 (32KB head cap), false for Pass 2 (full body).
     * @return bool
     */
    private static function body_match( string $body, array $patterns, bool $use_range ): bool {
        if ( empty( $patterns ) ) {
            return false;
        }
        $haystack = $use_range
            ? substr( $body, 0, self::BODY_SCAN_MAX_BYTES )
            : $body;
        $haystack_lower = strtolower( $haystack );
        foreach ( $patterns as $pat ) {
            if ( strpos( $haystack_lower, strtolower( (string) $pat ) ) !== false ) {
                return true;
            }
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
        // §5.4 step 3 trust-WP-first: body markers without WP context are unreliable
        // (regex may match unrelated customer content). Discard any class hits in
        // favor of 'non_wordpress' when no WP signals present.
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

    /**
     * Strip visible body text from HTML; return concatenation of safe-to-match zones.
     *
     * Preserved zones (wired across Tasks 2-4): <head> entire content, all HTML comments,
     * all <script>/<style>/<noscript> blocks, attribute values from a whitelist
     * (class/id/src/href/data-[*]/rel/type/name/content).
     *
     * Best-effort regex extraction (not DOMDocument). On HTML with no <head> AND no <body>,
     * returns the input unchanged (fallback).
     *
     * Per spec §5.3 + d-review Mi3 (name/content) + Mi4 (noscript).
     *
     * @param string $html
     * @return string
     */
    private static function extract_non_text_zones( string $html ): string {
        if ( $html === '' ) {
            return '';
        }
        self::$extract_call_count++;
        $has_head = (bool) preg_match( '/<head\b[^>]*>/i', $html );
        $has_body = (bool) preg_match( '/<body\b[^>]*>/i', $html );
        if ( ! $has_head && ! $has_body ) {
            return $html; // fallback per AC-T2-3
        }
        $parts = [];

        // 1. <head>...</head> wholesale
        if ( preg_match( '/<head\b[^>]*>([\s\S]*?)<\/head>/i', $html, $m ) ) {
            $parts[] = $m[1];
        }

        // 2. HTML comments (entire document)
        if ( preg_match_all( '/<!--[\s\S]*?-->/', $html, $matches ) ) {
            foreach ( $matches[0] as $c ) {
                $parts[] = $c;
            }
        }

        // 3. <script>...</script> content
        if ( preg_match_all( '/<script\b[^>]*>([\s\S]*?)<\/script>/i', $html, $matches ) ) {
            foreach ( $matches[1] as $c ) {
                $parts[] = $c;
            }
        }

        // 4. <style>...</style> content
        if ( preg_match_all( '/<style\b[^>]*>([\s\S]*?)<\/style>/i', $html, $matches ) ) {
            foreach ( $matches[1] as $c ) {
                $parts[] = $c;
            }
        }

        // 5. <noscript>...</noscript> content (d-review Mi4)
        if ( preg_match_all( '/<noscript\b[^>]*>([\s\S]*?)<\/noscript>/i', $html, $matches ) ) {
            foreach ( $matches[1] as $c ) {
                $parts[] = $c;
            }
        }

        // 6. Tag attribute values from whitelist (class/id/src/href/data-[*]/rel/type/name/content per d-review Mi3).
        //    style excluded — inline CSS commonly contains url(...) references unrelated to the plugin
        //    that would produce false-positive matches against target_body_pattern. Adding style here
        //    must be paired with the FP-corpus regression test in Task 11.
        $attr_re = '/\s(?:class|id|src|href|data-[\w\-]+|rel|type|name|content)\s*=\s*(?:"[^"]*"|\'[^\']*\')/i';
        if ( preg_match_all( $attr_re, $html, $matches ) ) {
            foreach ( $matches[0] as $a ) {
                $parts[] = $a;
            }
        }

        return implode( "\n", $parts );
    }

    /**
     * Match a regex pattern against a pre-scoped body string.
     * Caller is responsible for context-scoping via extract_non_text_zones (per spec §5.2 + d-review M3 hoist).
     *
     * Returns false on empty pattern, null/empty scoped body, malformed PCRE, or no match.
     *
     * @param ?string $scoped_body  Output of extract_non_text_zones( $body_slice ). Null = skip.
     * @param ?string $pattern      PCRE regex, e.g. '/\bflying[- _]?press\b/i'.
     * @return bool
     */
    private static function body_match_pattern( ?string $scoped_body, ?string $pattern ): bool {
        if ( ! $pattern || $scoped_body === null || $scoped_body === '' ) {
            return false;
        }
        // @ to silence PCRE warnings on bad patterns (defensive; pattern set is internal).
        $r = @preg_match( $pattern, $scoped_body );
        // Strict: preg_match returns 1=match, 0=no-match, false=PCRE error. Only match returns true.
        return $r === 1;
    }

    /**
     * Resolve MU-plugin directory path. Production reads WPMU_PLUGIN_DIR;
     * tests override via __test_set_mu_plugin_dir_override() without touching
     * PHP's define-once constants (rev-2 C1).
     */
    private static function get_mu_plugin_dir(): string {
        if ( self::$mu_plugin_dir_override !== null ) {
            return self::$mu_plugin_dir_override;
        }
        return defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '';
    }

    /**
     * Detect Kinsta-hosted WP install via MU-plugin file existence.
     * Kinsta auto-installs kinsta-mu-plugins on every site since ~2017; the file
     * path is stable. Spec §6.2.
     */
    private static function detect_kinsta_host(): bool {
        $dir = self::get_mu_plugin_dir();
        return $dir !== '' && file_exists( $dir . '/kinsta-mu-plugins/kinsta-mu-plugins.php' );
    }

    /**
     * Detect WP Engine-hosted WP install via MU-plugin file existence.
     * WP Engine auto-installs wpengine-common on every WPE WordPress site.
     * Spec §6.2.
     */
    private static function detect_wpe_host(): bool {
        $dir = self::get_mu_plugin_dir();
        return $dir !== '' && file_exists( $dir . '/wpengine-common/plugin.php' );
    }

    /**
     * Resolve Pantheon-env signal. Production reads PANTHEON_ENVIRONMENT and requires
     * a non-empty/non-null value (per rev-2 Mi1 — empty-string defines must NOT
     * register as a Pantheon site). Tests override via __test_set_pantheon_env_override().
     */
    private static function pantheon_env_defined(): bool {
        if ( self::$pantheon_env_override !== null ) {
            return self::$pantheon_env_override;
        }
        if ( ! defined( 'PANTHEON_ENVIRONMENT' ) ) {
            return false;
        }
        $val = constant( 'PANTHEON_ENVIRONMENT' );
        return $val !== '' && $val !== null;
    }

    /**
     * Detect Pantheon-hosted WP install via PANTHEON_ENVIRONMENT constant.
     * MU-plugin path varies across Pantheon deployment generations; the constant
     * is the canonical fingerprint. Spec §6.2.
     */
    private static function detect_pantheon_host(): bool {
        return self::pantheon_env_defined();
    }

    // --- Test seams (private-method exposure for unit testing) ---
    public static function __test_header_match( array $headers, array $patterns ): bool {
        return self::header_match( $headers, $patterns );
    }
    public static function __test_body_match( string $body, array $patterns, bool $use_range = true ): bool {
        return self::body_match( $body, $patterns, $use_range );
    }
    public static function __test_classify_outcome( bool $probe_failed, bool $is_wordpress, array $detected ): string {
        return self::classify_outcome( $probe_failed, $is_wordpress, $detected );
    }
    public static function __test_extract_non_text_zones( string $html ): string {
        return self::extract_non_text_zones( $html );
    }
    public static function __test_body_match_pattern( ?string $scoped_body, ?string $pattern ): bool {
        return self::body_match_pattern( $scoped_body, $pattern );
    }
    public static function __test_single_probe_attempt(
        string $url,
        int $timeout_seconds,
        bool $use_range = true
    ): array {
        return self::single_probe_attempt( $url, $timeout_seconds, $use_range );
    }
    public static function __test_set_mu_plugin_dir_override( ?string $dir ): void {
        self::$mu_plugin_dir_override = $dir;
    }
    public static function __test_set_pantheon_env_override( ?bool $val ): void {
        self::$pantheon_env_override = $val;
    }
    public static function __test_detect_kinsta_host(): bool {
        return self::detect_kinsta_host();
    }
    public static function __test_detect_wpe_host(): bool {
        return self::detect_wpe_host();
    }
    public static function __test_detect_pantheon_host(): bool {
        return self::detect_pantheon_host();
    }

    /**
     * AC-N2-SSRF (iv) — sanitize WP_Error / HTTP-error reason messages before returning to client.
     * Redacts IPs + server-internal paths; length-caps to 120.
     * Per d-review-r1 N2: redact only common server-internal path prefixes (not probed URL paths).
     */
    private static function sanitize_reason( string $reason ): string {
        $reason = preg_replace( '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?\b/', '<ip-redacted>', $reason );
        $reason = preg_replace( '#(?:^|\s)(/(?:home|var|usr|srv|etc|opt|root|tmp)/[^\s]*)#i', ' <internal-path-redacted>', $reason );
        return substr( $reason, 0, 120 );
    }

    /**
     * WordPress-detection signals per spec §5.1 last row.
     * Case-insensitive substring match against headers + first 32KB of body.
     */
    private static function is_wordpress_target( array $headers, string $body ): bool {
        $body_lower = strtolower( substr( $body, 0, self::BODY_SCAN_MAX_BYTES ) );
        if ( strpos( $body_lower, '<meta name="generator" content="wordpress' ) !== false ) return true;
        if ( strpos( $body_lower, 'wp-content/' )  !== false ) return true;
        if ( strpos( $body_lower, 'wp-includes/' ) !== false ) return true;
        if ( strpos( $body_lower, 'wp-json/' )     !== false ) return true;
        foreach ( $headers as $name => $val ) {
            if ( strtolower( (string) $name ) === 'x-pingback' ) return true;
        }
        return false;
    }

    /**
     * Single probe attempt — one wp_remote_get + classify what was returned.
     * Returns inconclusive on transient inconclusive result so caller can retry on URL #2.
     *
     * @param string $url             validated URL (caller responsible for SSRF gate)
     * @param int    $timeout_seconds wp_remote_get timeout
     * @param bool   $use_range       Pass 1 (true): send Range: bytes=0-32767 header; body_match
     *                                scans first 32KB head.
     *                                Pass 2 (false): NO Range header + 2MB limit_response_size cap;
     *                                body_match scans FULL body (T3 widening, spec §6.3).
     * @return array probe result; see class docblock for shape
     */
    private static function single_probe_attempt(
        string $url,
        int $timeout_seconds,
        bool $use_range = true
    ): array {
        $start_ms = (int) round( microtime( true ) * 1000 );

        $parts  = wp_parse_url( $url );
        $scheme = strtolower( $parts['scheme'] ?? '' );
        if ( ! in_array( $scheme, self::ALLOWED_SCHEMES, true ) ) {
            return [
                'outcome'             => 'probe_failed',
                'reason'              => 'invalid_scheme',
                'is_wordpress'        => false,
                'detected'            => [],
                'bypass_suffixes'     => [],
                'probed_url'          => $url,
                'probe_duration_ms'   => 0,
                'protocol_downgrade'  => false,
            ];
        }

        $request_headers = [
            'User-Agent' => 'CU-Scanner-Probe/1.0 (target-stack-detection)',
        ];
        if ( $use_range ) {
            $request_headers['Range'] = 'bytes=0-32767';
        }

        $request_args = [
            'timeout'     => $timeout_seconds,
            'redirection' => 3,
            'sslverify'   => true,
            'headers'     => $request_headers,
        ];
        if ( ! $use_range ) {
            // Pass 2 (full-body fetch): cap response size at 2MB to bound memory.
            // wp_remote_get truncates body to this limit; oversized responses don't fault.
            $request_args['limit_response_size'] = 2 * 1024 * 1024;
        }

        $response = wp_remote_get( $url, $request_args );

        $duration_ms = ( (int) round( microtime( true ) * 1000 ) ) - $start_ms;

        if ( is_wp_error( $response ) ) {
            return [
                'outcome'             => 'probe_failed',
                'reason'              => self::sanitize_reason( $response->get_error_message() ?: 'unreachable' ),
                'is_wordpress'        => false,
                'detected'            => [],
                'bypass_suffixes'     => [],
                'probed_url'          => $url,
                'probe_duration_ms'   => $duration_ms,
                'protocol_downgrade'  => false,
            ];
        }

        $status  = (int) wp_remote_retrieve_response_code( $response );
        $headers = wp_remote_retrieve_headers( $response );
        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $headers = $headers->getAll();
        } elseif ( ! is_array( $headers ) ) {
            $headers = (array) $headers;
        }
        $body = (string) wp_remote_retrieve_body( $response );

        if ( $status >= 500 || $status === 403 || $status === 429 ) {
            return [
                'outcome'             => 'probe_failed',
                'reason'              => 'HTTP ' . $status,
                'is_wordpress'        => false,
                'detected'            => [],
                'bypass_suffixes'     => [],
                'probed_url'          => $url,
                'probe_duration_ms'   => $duration_ms,
                'protocol_downgrade'  => false,
            ];
        }

        // 4xx other than 403/429 → inconclusive; caller may retry URL #2.
        if ( $status >= 400 ) {
            return [
                'outcome'             => 'inconclusive',
                'reason'              => 'HTTP ' . $status,
                'is_wordpress'        => false,
                'detected'            => [],
                'bypass_suffixes'     => [],
                'probed_url'          => $url,
                'probe_duration_ms'   => $duration_ms,
                'protocol_downgrade'  => false,
            ];
        }

        // Tier 2 hoist (d-review M3 + AC-T2-6): pre-compute scoped body ONCE per probe.
        // Pass 1 ($use_range=true) caps the slice at 32KB; Pass 2 uses the full body
        // (already capped at 2MB by limit_response_size). The scoped output feeds
        // every plugin's regex match in the loop below — DO NOT move this call inside
        // the loop, AC-T2-6 will fail and the cost analysis in spec §6.4.2 breaks.
        $body_slice  = $use_range ? substr( $body, 0, self::BODY_SCAN_MAX_BYTES ) : $body;
        $scoped_body = self::extract_non_text_zones( $body_slice );

        // Scan headers + body for each optimizer signature.
        $detected = [];
        foreach ( self::OPTIMIZERS as $plugin_file => $entry ) {
            $h_pat = $entry['target_headers']      ?? [];
            $b_pat = $entry['target_body_markers'] ?? [];
            $h_match = self::header_match( $headers, $h_pat );
            $b_match = self::body_match( $body, $b_pat, $use_range )
                    || self::body_match_pattern( $scoped_body, $entry['target_body_pattern'] ?? null );
            if ( $h_match || $b_match ) {
                $detected[] = [
                    'name'         => (string) $entry['name'],
                    'class'        => (string) ( $entry['class'] ?? '' ),
                    'bypass_query' => $entry['bypass_query'] ?? null,
                    'source'       => $h_match ? 'header' : 'body',
                ];
            }
        }
        $is_wordpress = self::is_wordpress_target( $headers, $body );

        $outcome = self::classify_outcome( false, $is_wordpress, $detected );

        // Build bypass_suffixes (only class A / A_star emit keys; mirrors build_bypass_suffixes contract).
        $bypass = [];
        foreach ( $detected as $d ) {
            $cls = $d['class'] ?? '';
            $key = $d['bypass_query'] ?? null;
            if ( in_array( $cls, [ 'A', 'A_star' ], true ) && is_string( $key ) && $key !== '' ) {
                $bypass[] = $key;
            }
        }

        return [
            // 'inconclusive' is a transient label only inside single_probe_attempt; the wrapper
            // resolves it to 'no_clue' / 'non_wordpress' per §5.4 step 4 if BOTH probes are inconclusive.
            'outcome'             => $outcome === 'no_clue' ? 'inconclusive' : $outcome,
            'reason'              => null,
            'is_wordpress'        => $is_wordpress,
            'detected'            => $detected,
            'bypass_suffixes'     => $bypass,
            'probed_url'          => $url,
            'probe_duration_ms'   => $duration_ms,
            'protocol_downgrade'  => false,
        ];
    }

    /**
     * Public wrapper — 2-attempt probe with 24h per-host transient cache.
     * Cache key includes scheme + port to avoid collision (spec §5.3 + d-review M5).
     */
    public static function probe_target_stack( string $url, ?string $fallback_url = null, int $timeout_seconds = 12 ): array {
        $parts  = wp_parse_url( $url );
        $scheme = strtolower( $parts['scheme'] ?? 'https' );
        $host   = strtolower( $parts['host']   ?? '' );
        $port   = (string) ( $parts['port']    ?? ( $scheme === 'http' ? '80' : '443' ) );
        $cache_key = 'cu_scanner_target_stack_' . md5( $scheme . '://' . $host . ':' . $port );

        $cached = get_transient( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) {
            $cached['cache_hit'] = true;
            return $cached;
        }

        $result = self::single_probe_attempt( $url, $timeout_seconds );
        if ( $result['outcome'] === 'inconclusive' && $fallback_url ) {
            $r2 = self::single_probe_attempt( $fallback_url, $timeout_seconds );
            if ( $r2['outcome'] !== 'inconclusive' ) {
                $result = $r2;
                $result['probed_url_2'] = $fallback_url;
            }
        }

        // FU-NEW-7: Pass 2 orchestration. Fires when Pass 1 (head-area scan) was inconclusive
        // AND the response was healthy (reason === null, i.e. not an HTTP/transport error).
        // Retries each URL with a full-body fetch (use_range=false) — T3 widening (spec §6.3)
        // scans the entire body up to the 2MB limit_response_size cap, closing the dead zone
        // between 32KB head and end-of-body for markers at any byte offset.
        // Spec rev 2.1 §3.2 + §3.3 + §3.8.
        if ( $result['outcome'] === 'inconclusive' && ( $result['reason'] ?? null ) === null ) {
            // Pass 2a: URL1 full body scan.
            $p2_url1 = self::single_probe_attempt(
                $url,
                $timeout_seconds,
                false  /* use_range — Pass 2: full body */
            );
            if ( $p2_url1['outcome'] !== 'inconclusive' ) {
                $final = $p2_url1;
            } elseif ( $fallback_url ) {
                // Pass 2b: URL2 full body scan.
                $p2_url2 = self::single_probe_attempt(
                    $fallback_url,
                    $timeout_seconds,
                    false  /* use_range — Pass 2: full body */
                );
                $final = $p2_url2['outcome'] !== 'inconclusive' ? $p2_url2 : $result;
            } else {
                $final = $result;
            }
            $result = $final;
        }

        // Resolve transient 'inconclusive' to 'no_clue' / 'non_wordpress' per §5.4 step 4.
        if ( $result['outcome'] === 'inconclusive' ) {
            $result['outcome'] = $result['is_wordpress'] ? 'no_clue' : 'non_wordpress';
        }

        $result['probed_url_1'] = $url;
        $result['cache_hit']    = false;
        set_transient( $cache_key, $result, 24 * HOUR_IN_SECONDS );
        return $result;
    }
}

<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\PluginDetector;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class PluginDetectorBypassSuffixesTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    // ------------------------------------------------------------------
    // build_bypass_suffixes
    // ------------------------------------------------------------------

    public function test_class_a_entry_is_included(): void {
        $typed = [
            'wp-rocket/wp-rocket.php' => [
                'name' => 'WP Rocket', 'class' => 'A', 'bypass_query' => 'nowprocket',
                'disable_method' => null, 'warning' => null,
            ],
        ];
        $result = PluginDetector::build_bypass_suffixes( $typed );
        $this->assertSame( [ 'nowprocket' ], $result );
    }

    public function test_class_a_star_entry_is_included(): void {
        $typed = [
            'litespeed-cache/litespeed-cache.php' => [
                'name' => 'LiteSpeed Cache', 'class' => 'A_star',
                'bypass_query' => 'LSCWP_CTRL=before_optm',
                'disable_method' => null, 'warning' => null,
            ],
        ];
        $result = PluginDetector::build_bypass_suffixes( $typed );
        $this->assertSame( [ 'LSCWP_CTRL=before_optm' ], $result );
    }

    public function test_class_b_entry_excluded(): void {
        $typed = [
            'wp-fastest-cache/wpFastestCache.php' => [
                'name' => 'WP Fastest Cache', 'class' => 'B', 'bypass_query' => null,
                'disable_method' => null, 'warning' => null,
            ],
        ];
        $result = PluginDetector::build_bypass_suffixes( $typed );
        $this->assertSame( [], $result );
    }

    public function test_class_c_entry_excluded(): void {
        $typed = [
            'flying-press/flying-press.php' => [
                'name' => 'FlyingPress', 'class' => 'C', 'bypass_query' => null,
                'disable_method' => 'flying_press',
                'warning' => 'CSS/JS optimization will be paused.',
            ],
        ];
        $result = PluginDetector::build_bypass_suffixes( $typed );
        $this->assertSame( [], $result );
    }

    public function test_only_b_and_c_returns_empty(): void {
        $typed = [
            'w3-total-cache/w3-total-cache.php' => [
                'name' => 'W3 Total Cache', 'class' => 'B', 'bypass_query' => null,
                'disable_method' => null, 'warning' => null,
            ],
            'sg-cachepress/sg-cachepress.php' => [
                'name' => 'SiteGround Optimizer', 'class' => 'C', 'bypass_query' => null,
                'disable_method' => 'sg_optimizer', 'warning' => 'paused.',
            ],
        ];
        $result = PluginDetector::build_bypass_suffixes( $typed );
        $this->assertSame( [], $result );
    }

    public function test_multi_optimizer_rocket_plus_perfmatters(): void {
        // WP Rocket + Perfmatters → 2 entries in iteration order
        $typed = [
            'wp-rocket/wp-rocket.php' => [
                'name' => 'WP Rocket', 'class' => 'A', 'bypass_query' => 'nowprocket',
                'disable_method' => null, 'warning' => null,
            ],
            'perfmatters/perfmatters.php' => [
                'name' => 'Perfmatters', 'class' => 'A', 'bypass_query' => 'perfmattersoff',
                'disable_method' => null, 'warning' => null,
            ],
        ];
        $result = PluginDetector::build_bypass_suffixes( $typed );
        $this->assertSame( [ 'nowprocket', 'perfmattersoff' ], $result );
    }

    public function test_empty_input_returns_empty(): void {
        $result = PluginDetector::build_bypass_suffixes( [] );
        $this->assertSame( [], $result );
    }

    public function test_null_bypass_query_on_class_a_skipped(): void {
        // Edge case: class A but bypass_query is null (should not happen in prod but defensive)
        $typed = [
            'some-plugin/main.php' => [
                'name' => 'SomePlugin', 'class' => 'A', 'bypass_query' => null,
                'disable_method' => null, 'warning' => null,
            ],
        ];
        $result = PluginDetector::build_bypass_suffixes( $typed );
        $this->assertSame( [], $result );
    }

    public function test_empty_string_bypass_query_on_class_a_skipped(): void {
        $typed = [
            'some-plugin/main.php' => [
                'name' => 'SomePlugin', 'class' => 'A', 'bypass_query' => '',
                'disable_method' => null, 'warning' => null,
            ],
        ];
        $result = PluginDetector::build_bypass_suffixes( $typed );
        $this->assertSame( [], $result );
    }

    // ------------------------------------------------------------------
    // plugin_file_to_enum
    // ------------------------------------------------------------------

    public function test_known_files_map_correctly(): void {
        $cases = [
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
        foreach ( $cases as $file => $expected ) {
            $this->assertSame( $expected, PluginDetector::plugin_file_to_enum( $file ), "Failed for $file" );
        }
    }

    public function test_unknown_file_returns_unknown(): void {
        $this->assertSame( 'unknown', PluginDetector::plugin_file_to_enum( 'some-unknown/plugin.php' ) );
    }

    public function test_empty_string_returns_unknown(): void {
        $this->assertSame( 'unknown', PluginDetector::plugin_file_to_enum( '' ) );
    }
}

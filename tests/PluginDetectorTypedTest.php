<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\PluginDetector;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class PluginDetectorTypedTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_class_a_entry_carries_bypass_query(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => $f === 'wp-rocket/wp-rocket.php' );
        $entries = ( new PluginDetector() )->detect_typed();
        $this->assertArrayHasKey( 'wp-rocket/wp-rocket.php', $entries );
        $this->assertSame( 'A',          $entries['wp-rocket/wp-rocket.php']['class'] );
        $this->assertSame( 'nowprocket', $entries['wp-rocket/wp-rocket.php']['bypass_query'] );
        $this->assertNull( $entries['wp-rocket/wp-rocket.php']['disable_method'] );
    }

    public function test_perfmatters_now_class_a_with_perfmattersoff(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => $f === 'perfmatters/perfmatters.php' );
        $entries = ( new PluginDetector() )->detect_typed();
        $this->assertSame( 'A',              $entries['perfmatters/perfmatters.php']['class'] );
        $this->assertSame( 'perfmattersoff', $entries['perfmatters/perfmatters.php']['bypass_query'] );
    }

    public function test_litespeed_class_a_star(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => $f === 'litespeed-cache/litespeed-cache.php' );
        $entries = ( new PluginDetector() )->detect_typed();
        $this->assertSame( 'A_star',                  $entries['litespeed-cache/litespeed-cache.php']['class'] );
        $this->assertSame( 'LSCWP_CTRL=before_optm',  $entries['litespeed-cache/litespeed-cache.php']['bypass_query'] );
    }

    public function test_class_c_flying_press_carries_disable_method(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => $f === 'flying-press/flying-press.php' );
        $entries = ( new PluginDetector() )->detect_typed();
        $this->assertSame( 'C',            $entries['flying-press/flying-press.php']['class'] );
        $this->assertNull( $entries['flying-press/flying-press.php']['bypass_query'] );
        $this->assertSame( 'flying_press', $entries['flying-press/flying-press.php']['disable_method'] );
    }

    public function test_class_b_breeze_no_query_no_disable(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => $f === 'breeze/breeze.php' );
        $entries = ( new PluginDetector() )->detect_typed();
        $this->assertSame( 'B', $entries['breeze/breeze.php']['class'] );
        $this->assertNull( $entries['breeze/breeze.php']['bypass_query'] );
        $this->assertNull( $entries['breeze/breeze.php']['disable_method'] );
    }

    public function test_hummingbird_class_b_when_minify_off(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => $f === 'hummingbird-performance/wp-hummingbird.php' );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'wphb_settings', [] )
            ->andReturn( [ 'minify' => [ 'enabled' => false ] ] );
        $entries = ( new PluginDetector() )->detect_typed();
        $e = $entries['hummingbird-performance/wp-hummingbird.php'];
        $this->assertSame( 'B', $e['class'] );
        $this->assertNull( $e['disable_method'] );
    }

    public function test_hummingbird_class_c_when_minify_on(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => $f === 'hummingbird-performance/wp-hummingbird.php' );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'wphb_settings', [] )
            ->andReturn( [ 'minify' => [ 'enabled' => true ] ] );
        $entries = ( new PluginDetector() )->detect_typed();
        $e = $entries['hummingbird-performance/wp-hummingbird.php'];
        $this->assertSame( 'C', $e['class'] );
        $this->assertSame( 'hummingbird', $e['disable_method'] );
    }

    public function test_hummingbird_class_b_when_settings_missing(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => $f === 'hummingbird-performance/wp-hummingbird.php' );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'wphb_settings', [] )
            ->andReturn( [] );  // no minify key
        $entries = ( new PluginDetector() )->detect_typed();
        $this->assertSame( 'B', $entries['hummingbird-performance/wp-hummingbird.php']['class'] );
    }

    public function test_inactive_plugin_not_returned(): void {
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        $entries = ( new PluginDetector() )->detect_typed();
        $this->assertSame( [], $entries );
    }

    public function test_multi_optimizer_returns_both(): void {
        // WP Rocket + Perfmatters = composition case from spec §3.5
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => in_array( $f, [
                'wp-rocket/wp-rocket.php',
                'perfmatters/perfmatters.php',
            ], true ) );
        $entries = ( new PluginDetector() )->detect_typed();
        $this->assertCount( 2, $entries );
        $this->assertSame( 'nowprocket',     $entries['wp-rocket/wp-rocket.php']['bypass_query'] );
        $this->assertSame( 'perfmattersoff', $entries['perfmatters/perfmatters.php']['bypass_query'] );
    }
}

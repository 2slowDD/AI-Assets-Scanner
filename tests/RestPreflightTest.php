<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\RestPreflight;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class RestPreflightTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_handle_returns_empty_class_c_when_only_class_a_active(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => $f === 'wp-rocket/wp-rocket.php' );

        $request  = new \WP_REST_Request( 'POST' );
        $response = RestPreflight::handle( $request );

        $this->assertSame( [], $response['class_c_active'] );
        $this->assertGreaterThan( 0, $response['estimated_minutes'] );
    }

    public function test_handle_returns_empty_class_c_when_flying_press_active_post_reclass(): void {
        // FlyingPress is class A post-reclass (Phase 1 + Phase 3) — it must NOT
        // appear in class_c_active even when locally active.
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => $f === 'flying-press/flying-press.php' );

        $request  = new \WP_REST_Request( 'POST' );
        $response = RestPreflight::handle( $request );

        $this->assertSame( [], $response['class_c_active'],
            'FlyingPress reclassed C -> A; preflight class_c_active must be empty' );
    }

    public function test_handle_includes_hummingbird_only_when_minify_enabled(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => $f === 'hummingbird-performance/wp-hummingbird.php' );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'wphb_settings', [] )
            ->andReturn( [ 'minify' => [ 'enabled' => false ] ] );

        $request  = new \WP_REST_Request( 'POST' );
        $response = RestPreflight::handle( $request );

        $this->assertSame( [], $response['class_c_active'],
            'Hummingbird with minify off must NOT trigger consent modal' );
    }

    public function test_handle_includes_hummingbird_when_minify_enabled(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => $f === 'hummingbird-performance/wp-hummingbird.php' );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'wphb_settings', [] )
            ->andReturn( [ 'minify' => [ 'enabled' => true ] ] );

        $request  = new \WP_REST_Request( 'POST' );
        $response = RestPreflight::handle( $request );

        $this->assertCount( 1, $response['class_c_active'] );
        $this->assertSame( 'hummingbird', $response['class_c_active'][0]['slug'] );
    }

    public function test_permission_callback_requires_manage_options(): void {
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )
            ->once()
            ->andReturn( false );
        $request = new \WP_REST_Request( 'POST' );
        $result  = RestPreflight::permission_callback( $request );
        $this->assertFalse( $result );
    }

    public function test_permission_callback_allows_manage_options(): void {
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )
            ->andReturn( true );
        $request = new \WP_REST_Request( 'POST' );
        $result  = RestPreflight::permission_callback( $request );
        $this->assertTrue( $result );
    }
}

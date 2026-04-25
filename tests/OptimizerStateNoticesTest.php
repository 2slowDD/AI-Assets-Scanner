<?php
namespace CUScanner\Tests;

use CUScanner\Admin\OptimizerStateNotices;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class OptimizerStateNoticesTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_render_banner_is_silent_when_no_state(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_optimizer_state', null )
            ->andReturn( null );

        ob_start();
        OptimizerStateNotices::render_banner();
        $out = ob_get_clean();

        $this->assertSame( '', $out );
    }

    public function test_render_banner_outputs_when_state_present(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_optimizer_state', null )
            ->andReturn( [
                'scan_id' => 'sid',
                'created_at' => time(),
                'expires_at' => time() + 100,
                'snapshots' => [ 'flying_press' => [] ],
            ] );
        WP_Mock::userFunction( 'admin_url' )
            ->with( 'admin-post.php?action=aias_force_restore' )
            ->andReturn( 'http://example.test/wp-admin/admin-post.php?action=aias_force_restore' );
        WP_Mock::userFunction( 'wp_create_nonce' )
            ->with( 'aias_force_restore' )
            ->andReturn( 'nonce-abc' );
        WP_Mock::userFunction( 'esc_html' )
            ->andReturnUsing( fn( $s ) => $s );
        WP_Mock::userFunction( 'esc_url' )
            ->andReturnUsing( fn( $s ) => $s );
        WP_Mock::userFunction( 'esc_attr' )
            ->andReturnUsing( fn( $s ) => $s );
        WP_Mock::userFunction( 'esc_html__' )
            ->andReturnUsing( fn( $s ) => $s );

        ob_start();
        OptimizerStateNotices::render_banner();
        $out = ob_get_clean();

        $this->assertStringContainsString( 'notice-warning', $out );
        $this->assertStringContainsString( 'flying_press', $out );
        $this->assertStringContainsString( 'aias_force_restore', $out );
        $this->assertStringContainsString( 'nonce-abc', $out );
    }

    public function test_handle_force_restore_requires_capability(): void {
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )
            ->andReturn( false );
        WP_Mock::userFunction( 'wp_die' )
            ->once()
            ->andReturnUsing( function () { throw new \RuntimeException( 'wp_die' ); } );

        $this->expectException( \RuntimeException::class );
        OptimizerStateNotices::handle_force_restore();
    }

    public function test_handle_force_restore_runs_restore_and_redirects(): void {
        WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
        WP_Mock::userFunction( 'check_admin_referer' )
            ->with( 'aias_force_restore' )
            ->andReturn( 1 );
        WP_Mock::userFunction( 'get_option' )
            ->with( 'aias_optimizer_state', null )
            ->andReturn( [
                'scan_id' => 'sid', 'created_at' => time(),
                'expires_at' => time() + 100,
                'snapshots' => [],  // empty — orchestrator no-ops
            ] );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'aias_optimizer_state' );
        WP_Mock::userFunction( 'admin_url' )
            ->andReturn( 'http://example.test/wp-admin/admin.php?page=cu-scanner' );
        WP_Mock::userFunction( 'add_query_arg' )
            ->andReturnUsing( fn( $k, $v, $u ) => "{$u}&{$k}={$v}" );
        WP_Mock::userFunction( 'wp_safe_redirect' )
            ->once()
            ->andReturnUsing( function () { throw new \RuntimeException( 'redirect' ); } );

        try {
            OptimizerStateNotices::handle_force_restore();
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'redirect', $e->getMessage() );
        }
    }
}

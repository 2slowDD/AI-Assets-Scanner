<?php
namespace CUScanner\Tests;

use CUScanner\Admin\PrivateUpdater;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class PrivateUpdaterTest extends TestCase {
    private const PLUGIN_FILE = 'ai-assets-scanner/ai-assets-scanner.php';

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        PrivateUpdater::set_manifest_for_testing( null );
    }

    public function tearDown(): void {
        PrivateUpdater::set_manifest_for_testing( null );
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_update_check_adds_response_for_published_newer_manifest(): void {
        PrivateUpdater::set_manifest_for_testing( [
            'published'       => true,
            'version'         => '1.7.3',
            'download_url'    => 'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.3/ai-assets-scanner.zip',
            'sha256'          => str_repeat( 'a', 64 ),
            'requires_wp'     => '6.2',
            'tested_wp'       => '7.0',
            'requires_php'    => '8.0',
            'changelog_url'   => 'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.3/changelog.html',
        ] );

        $updater   = new PrivateUpdater( self::PLUGIN_FILE, '1.7.1' );
        $transient = (object) [ 'response' => [] ];
        $result    = $updater->filter_update_transient( $transient );

        $this->assertArrayHasKey( self::PLUGIN_FILE, $result->response );
        $this->assertSame( '1.7.3', $result->response[ self::PLUGIN_FILE ]->new_version );
        $this->assertSame( '7.0', $result->response[ self::PLUGIN_FILE ]->tested );
        $this->assertSame( str_repeat( 'a', 64 ), $result->response[ self::PLUGIN_FILE ]->sha256 );
        $this->assertSame(
            'https://example.test/wp-content/plugins/ai-assets-scanner/admin/images/ai-assets-scanner-logo.png',
            $result->response[ self::PLUGIN_FILE ]->icons['default']
        );
    }

    public function test_update_check_ignores_unpublished_manifest(): void {
        PrivateUpdater::set_manifest_for_testing( [
            'published'    => false,
            'version'      => '1.7.3',
            'download_url' => 'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.3/ai-assets-scanner.zip',
            'sha256'       => str_repeat( 'b', 64 ),
        ] );

        $updater   = new PrivateUpdater( self::PLUGIN_FILE, '1.7.1' );
        $transient = (object) [ 'response' => [] ];
        $result    = $updater->filter_update_transient( $transient );

        $this->assertArrayNotHasKey( self::PLUGIN_FILE, $result->response );
    }

    public function test_row_meta_matches_private_plugin_dashboard_information(): void {
        $updater = new PrivateUpdater( self::PLUGIN_FILE, '1.7.3' );
        WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $value ) => htmlspecialchars( $value, ENT_QUOTES ) );
        WP_Mock::userFunction( 'esc_html' )->andReturnUsing( fn( $value ) => htmlspecialchars( $value, ENT_QUOTES ) );

        $meta = $updater->filter_plugin_row_meta( [], self::PLUGIN_FILE );

        $this->assertStringContainsString( 'View details', implode( ' ', $meta ) );
        $this->assertStringContainsString( 'https://wpservice.pro/our-products/ai-assets-scanner/', implode( ' ', $meta ) );
        $this->assertContains( 'Tested upto: <strong>v7.0</strong>', $meta );
        $this->assertContains( 'Status: <span style="color:#2271b1">Available</span>', $meta );
        $this->assertStringNotContainsString( 'Ratings:', implode( ' ', $meta ) );
        $this->assertStringNotContainsString( 'Reviews:', implode( ' ', $meta ) );
    }

    public function test_plugin_information_uses_header_logo_as_icon(): void {
        PrivateUpdater::set_manifest_for_testing( [
            'published'    => true,
            'version'      => '1.7.3',
            'download_url' => 'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.3/ai-assets-scanner.zip',
            'sha256'       => str_repeat( 'd', 64 ),
        ] );

        $updater = new PrivateUpdater( self::PLUGIN_FILE, '1.7.2' );
        $info    = $updater->filter_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'ai-assets-scanner' ] );

        $this->assertSame(
            'https://example.test/wp-content/plugins/ai-assets-scanner/admin/images/ai-assets-scanner-logo.png',
            $info->icons['default']
        );
    }

    public function test_checksum_mismatch_blocks_downloaded_package(): void {
        PrivateUpdater::set_manifest_for_testing( [
            'published'    => true,
            'version'      => '1.7.3',
            'download_url' => 'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.3/ai-assets-scanner.zip',
            'sha256'       => str_repeat( 'c', 64 ),
        ] );

        $tmp = tempnam( sys_get_temp_dir(), 'aas-package-' );
        file_put_contents( $tmp, 'tampered' );

        WP_Mock::userFunction( 'download_url' )->andReturn( $tmp );
        WP_Mock::userFunction( 'wp_delete_file' )->once()->with( $tmp )->andReturnUsing( fn( $file ) => @unlink( $file ) );

        $updater = new PrivateUpdater( self::PLUGIN_FILE, '1.7.1' );
        $result  = $updater->filter_pre_download(
            false,
            'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.3/ai-assets-scanner.zip',
            null,
            [ 'plugin' => self::PLUGIN_FILE ]
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'aias_checksum_mismatch', $result->get_error_code() );
        $this->assertFileDoesNotExist( $tmp );
    }
}

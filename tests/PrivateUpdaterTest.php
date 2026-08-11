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

    public function test_update_check_removes_stale_same_version_response(): void {
        PrivateUpdater::set_manifest_for_testing( [
            'published'    => true,
            'version'      => '1.7.7',
            'download_url' => 'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.7/ai-assets-scanner.zip',
            'sha256'       => str_repeat( 'e', 64 ),
        ] );

        $updater   = new PrivateUpdater( self::PLUGIN_FILE, '1.7.7' );
        $transient = (object) [
            'response' => [
                self::PLUGIN_FILE => (object) [
                    'new_version' => '1.7.7',
                    'package'     => 'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.7/ai-assets-scanner.zip',
                ],
            ],
        ];

        $result = $updater->filter_update_transient( $transient );

        $this->assertArrayNotHasKey( self::PLUGIN_FILE, $result->response );
    }

    public function test_existing_update_transient_removes_stale_same_version_response_without_manifest_fetch(): void {
        $updater   = new PrivateUpdater( self::PLUGIN_FILE, '1.7.7' );
        $transient = (object) [
            'response' => [
                self::PLUGIN_FILE => (object) [
                    'new_version' => '1.7.7',
                    'package'     => 'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.7/ai-assets-scanner.zip',
                ],
            ],
        ];

        $result = $updater->filter_existing_update_transient( $transient );

        $this->assertArrayNotHasKey( self::PLUGIN_FILE, $result->response );
    }

    public function test_row_meta_matches_private_plugin_dashboard_information(): void {
        $updater = new PrivateUpdater( self::PLUGIN_FILE, '1.7.3' );
        // Render-path stubs. Since 1.7.93b row meta reads the cached manifest, so this needs
        // get_transient stubbed; it must still make no HTTP call (asserted in the helper).
        $this->stub_escapers();

        $meta = $updater->filter_plugin_row_meta( [], self::PLUGIN_FILE );

        $this->assertStringContainsString( 'View details', implode( ' ', $meta ) );
        $this->assertStringContainsString( 'https://wpservice.pro/our-products/ai-assets-scanner/', implode( ' ', $meta ) );
        $this->assertContains( 'Tested upto: <strong>v7.0</strong>', $meta );
        $this->assertContains( 'Status: <span style="color:#2271b1">Available</span>', $meta );
        $this->assertStringNotContainsString( 'Ratings:', implode( ' ', $meta ) );
        $this->assertStringNotContainsString( 'Reviews:', implode( ' ', $meta ) );
    }

    // ---------------------------------------------------------------------------------
    // FU-AAS-UPDATE-DATE-STUCK — the release date must come from the manifest, never from a
    // compiled-in constant. Every case below drives the REAL filter_plugin_row_meta() /
    // filter_plugin_information() against a REAL manifest; none injects a formatted date.
    // ---------------------------------------------------------------------------------

    /**
     * Stub the WP functions the row-meta render path uses — and assert the one it must NOT.
     * filter_plugin_row_meta() paints on every Plugins screen, so a remote fetch there would be
     * a blocking 10s-timeout GET per page view. wp_remote_get()->never() is the guard: it goes
     * red the moment display code reaches for the fetching accessor again.
     */
    private function stub_escapers(): void {
        WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $value ) => htmlspecialchars( $value, ENT_QUOTES ) );
        WP_Mock::userFunction( 'esc_html' )->andReturnUsing( fn( $value ) => htmlspecialchars( $value, ENT_QUOTES ) );
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_get' )->never();
    }

    public function test_row_meta_updated_date_comes_from_the_manifest(): void {
        PrivateUpdater::set_manifest_for_testing( [
            'published'   => true,
            'version'     => '1.7.93b',
            'released_at' => '2026-08-09T00:00:00Z',
            'tested_wp'   => '7.0.3',
        ] );
        $this->stub_escapers();

        $meta = ( new PrivateUpdater( self::PLUGIN_FILE, '1.7.92b' ) )
            ->filter_plugin_row_meta( [], self::PLUGIN_FILE );

        $this->assertContains( 'Updated: <strong>August 9, 2026</strong>', $meta );
        // The reported symptom: the row must never render the old compiled-in date again.
        $this->assertStringNotContainsString( 'May 26, 2026', implode( ' ', $meta ) );
        // Same root cause, second field: tested_wp moved to 7.0.3 in 1.7.92b but the row kept
        // showing the constant.
        $this->assertContains( 'Tested upto: <strong>v7.0.3</strong>', $meta );
    }

    public function test_row_meta_omits_the_date_when_the_manifest_has_none(): void {
        PrivateUpdater::set_manifest_for_testing( [ 'published' => true, 'version' => '1.7.93b' ] );
        $this->stub_escapers();

        $meta = ( new PrivateUpdater( self::PLUGIN_FILE, '1.7.92b' ) )
            ->filter_plugin_row_meta( [], self::PLUGIN_FILE );

        $this->assertStringNotContainsString( 'Updated:', implode( ' ', $meta ),
            'a missing date must be omitted, never back-filled with a hardcoded one' );
        $this->assertContains( 'Status: <span style="color:#2271b1">Available</span>', $meta );
    }

    /**
     * released_at is remote input (wp-compliance rule 1). A tampered relative value must be
     * rejected by shape, not passed to strtotime() where it would render a date that changes
     * on every page load.
     */
    public function test_row_meta_rejects_a_malformed_released_at(): void {
        foreach ( [ 'tomorrow', 'now', '2026-08-09', '', '2026-13-45T99:99:99Z' ] as $bad ) {
            PrivateUpdater::set_manifest_for_testing( [ 'published' => true, 'released_at' => $bad ] );
            $this->stub_escapers();

            $meta = ( new PrivateUpdater( self::PLUGIN_FILE, '1.7.92b' ) )
                ->filter_plugin_row_meta( [], self::PLUGIN_FILE );

            $this->assertStringNotContainsString( 'Updated:', implode( ' ', $meta ),
                'malformed released_at must be rejected, got rendered for: ' . var_export( $bad, true ) );
        }
    }

    /** A junk version string must fall back to the constant rather than render as-is. */
    public function test_row_meta_rejects_a_malformed_version(): void {
        PrivateUpdater::set_manifest_for_testing( [ 'published' => true, 'tested_wp' => '<script>x</script>' ] );
        $this->stub_escapers();

        $meta = ( new PrivateUpdater( self::PLUGIN_FILE, '1.7.92b' ) )
            ->filter_plugin_row_meta( [], self::PLUGIN_FILE );

        $this->assertContains( 'Tested upto: <strong>v7.0</strong>', $meta );
        $this->assertStringNotContainsString( 'script', implode( ' ', $meta ) );
    }

    public function test_plugin_information_exposes_last_updated_from_the_manifest(): void {
        PrivateUpdater::set_manifest_for_testing( [
            'published'    => true,
            'version'      => '1.7.93b',
            'released_at'  => '2026-08-09T12:34:56Z',
            'download_url' => 'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.93b/ai-assets-scanner.zip',
            'sha256'       => str_repeat( 'd', 64 ),
        ] );

        $info = ( new PrivateUpdater( self::PLUGIN_FILE, '1.7.92b' ) )
            ->filter_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'ai-assets-scanner' ] );

        $this->assertSame( '2026-08-09 12:34:56', $info->last_updated,
            'WP renders last_updated as Y-m-d H:i:s' );
    }

    public function test_plugin_information_last_updated_is_empty_when_unknown(): void {
        PrivateUpdater::set_manifest_for_testing( [
            'published'    => true,
            'version'      => '1.7.93b',
            'download_url' => 'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.93b/ai-assets-scanner.zip',
            'sha256'       => str_repeat( 'd', 64 ),
        ] );

        $info = ( new PrivateUpdater( self::PLUGIN_FILE, '1.7.92b' ) )
            ->filter_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'ai-assets-scanner' ] );

        $this->assertSame( '', $info->last_updated, 'core omits the field on an empty string' );
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

    public function test_checksum_validation_allows_stale_same_version_update_transient(): void {
        $package_body = 'official-package';
        $expected_sha = hash( 'sha256', $package_body );

        PrivateUpdater::set_manifest_for_testing( [
            'published'    => true,
            'version'      => '1.7.6',
            'download_url' => 'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.6/ai-assets-scanner.zip',
            'sha256'       => $expected_sha,
        ] );

        $tmp = tempnam( sys_get_temp_dir(), 'aas-package-' );
        file_put_contents( $tmp, $package_body );

        WP_Mock::userFunction( 'download_url' )->andReturn( $tmp );

        $updater = new PrivateUpdater( self::PLUGIN_FILE, '1.7.6' );
        $result  = $updater->filter_pre_download(
            false,
            'https://updates.wpservice.pro/ai-assets-scanner/releases/1.7.6/ai-assets-scanner.zip',
            null,
            [ 'plugin' => self::PLUGIN_FILE ]
        );

        $this->assertSame( $tmp, $result );
        $this->assertFileExists( $tmp );

        unlink( $tmp );
    }
}

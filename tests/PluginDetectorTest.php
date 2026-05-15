<?php
// tests/PluginDetectorTest.php
namespace CUScanner\Tests;

use CUScanner\Scanner\PluginDetector;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class PluginDetectorTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_detects_wp_rocket_as_auto_bypass(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'wp-rocket/wp-rocket.php' )->andReturn( true );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );

        $result = ( new PluginDetector() )->detect();
        $this->assertArrayHasKey( 'wp-rocket', $result['auto_bypass'] );
        $this->assertContains( 'nowprocket', $result['auto_bypass']['wp-rocket'] );
    }

    public function test_detects_autoptimize_as_auto_bypass(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'autoptimize/autoptimize.php' )->andReturn( true );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );

        $result = ( new PluginDetector() )->detect();
        $this->assertArrayHasKey( 'autoptimize', $result['auto_bypass'] );
    }

    public function test_detects_nitropack_as_soft_block(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'nitropack/nitropack.php' )->andReturn( true );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );

        $result = ( new PluginDetector() )->detect();
        $this->assertArrayHasKey( 'NitroPack', $result['soft_block'] );
    }

    public function test_detects_flying_scripts_as_soft_block(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'flying-scripts/flying-scripts.php' )->andReturn( true );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );

        $result = ( new PluginDetector() )->detect();
        $this->assertArrayHasKey( 'Flying Scripts', $result['soft_block'] );
    }

    public function test_detects_perfmatters_as_soft_warn(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'perfmatters/perfmatters.php' )->andReturn( true );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );

        $result = ( new PluginDetector() )->detect();
        $this->assertArrayHasKey( 'Perfmatters', $result['soft_warn'] );
    }

    public function test_detects_code_unloader_version(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'code-unloader/code-unloader.php' )->andReturn( true );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'get_plugin_data' )->andReturn( [ 'Version' => '1.2.0' ] );

        $result = ( new PluginDetector() )->detect();
        // Old version → soft block
        $this->assertArrayHasKey( 'Code Unloader', $result['soft_block'] );
    }

    public function test_code_unloader_139_is_auto_bypassed(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'code-unloader/code-unloader.php' )->andReturn( true );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'get_plugin_data' )->andReturn( [ 'Version' => '1.3.9' ] );

        $result = ( new PluginDetector() )->detect();
        $this->assertArrayHasKey( 'code-unloader', $result['auto_bypass'] );
    }

    public function test_cu_missing_is_true_when_code_unloader_not_active(): void {
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );

        $result = ( new PluginDetector() )->detect();
        $this->assertArrayHasKey( 'cu_missing', $result );
        $this->assertTrue( $result['cu_missing'] );
    }

    public function test_cu_missing_is_false_when_code_unloader_active(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'code-unloader/code-unloader.php' )->andReturn( true );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'get_plugin_data' )->andReturn( [ 'Version' => '1.3.9' ] );

        $result = ( new PluginDetector() )->detect();
        $this->assertArrayHasKey( 'cu_missing', $result );
        $this->assertFalse( $result['cu_missing'] );
    }

    public function test_detects_wordfence_as_security_warn(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'wordfence/wordfence.php' )->andReturn( true );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'admin_url' )
            ->with( 'admin.php?page=cu-scanner-settings' )
            ->andReturn( 'http://example.com/wp-admin/admin.php?page=cu-scanner-settings' );

        $result = ( new PluginDetector() )->detect();
        $this->assertArrayHasKey( 'Wordfence', $result['security_warn'] );
        $this->assertArrayHasKey( 'reason', $result['security_warn']['Wordfence'] );
        $this->assertArrayHasKey( 'settings_url', $result['security_warn']['Wordfence'] );
    }

    public function test_detects_wordfence_login_security_as_security_warn(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'wordfence-login-security/wordfence-login-security.php' )->andReturn( true );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'admin_url' )
            ->with( 'admin.php?page=cu-scanner-settings' )
            ->andReturn( 'http://example.com/wp-admin/admin.php?page=cu-scanner-settings' );

        $result = ( new PluginDetector() )->detect();
        $this->assertArrayHasKey( 'Wordfence Login Security', $result['security_warn'] );
    }

    public function test_detects_cloudflare_plugin_as_security_warn_with_anchor(): void {
        WP_Mock::userFunction( 'is_plugin_active' )
            ->with( 'cloudflare/cloudflare.php' )->andReturn( true );
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        WP_Mock::userFunction( 'admin_url' )
            ->with( 'admin.php?page=cu-scanner-settings' )
            ->andReturn( 'http://example.com/wp-admin/admin.php?page=cu-scanner-settings' );

        $result = ( new PluginDetector() )->detect();
        $this->assertArrayHasKey( 'Cloudflare', $result['security_warn'] );
        $this->assertStringContainsString(
            '#cu-cloudflare-waf-bypass',
            $result['security_warn']['Cloudflare']['settings_url']
        );
    }

    public function test_no_plugins_returns_empty_result(): void {
        WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
        $result = ( new PluginDetector() )->detect();
        $this->assertSame( [], $result['auto_bypass'] );
        $this->assertSame( [], $result['soft_block'] );
        $this->assertSame( [], $result['soft_warn'] );
        $this->assertSame( [], $result['security_warn'] );
        $this->assertTrue( $result['cu_missing'] );   // add this line
    }

    /**
     * AC-N2 — every OPTIMIZERS entry must define target_headers and target_body_markers
     * sub-keys (arrays, possibly empty). Plan §1 + spec §5.1.
     */
    public function test_optimizers_table_has_target_detection_subkeys() {
        $reflection = new \ReflectionClass( PluginDetector::class );
        $optimizers = $reflection->getConstant( 'OPTIMIZERS' );
        $this->assertNotFalse( $optimizers, 'OPTIMIZERS constant must be accessible' );

        foreach ( $optimizers as $plugin_file => $entry ) {
            $this->assertArrayHasKey( 'target_headers',      $entry, "{$plugin_file}: missing target_headers sub-key" );
            $this->assertArrayHasKey( 'target_body_markers', $entry, "{$plugin_file}: missing target_body_markers sub-key" );
            $this->assertIsArray( $entry['target_headers'],      "{$plugin_file}: target_headers must be array" );
            $this->assertIsArray( $entry['target_body_markers'], "{$plugin_file}: target_body_markers must be array" );
        }
    }

    /**
     * AC-N2-9-unit — FlyingPress reclass C → A per spec §5.2.
     */
    public function test_flying_press_is_class_a_with_no_optimize() {
        $reflection = new \ReflectionClass( PluginDetector::class );
        $optimizers = $reflection->getConstant( 'OPTIMIZERS' );
        $fp = $optimizers['flying-press/flying-press.php'] ?? null;
        $this->assertNotNull( $fp, 'FlyingPress entry must exist in OPTIMIZERS' );
        $this->assertSame( 'A',           $fp['class'],          'FlyingPress must be class A (reclassified from C)' );
        $this->assertSame( 'no_optimize', $fp['bypass_query'],   'FlyingPress bypass_query must be no_optimize' );
        $this->assertNull( $fp['disable_method'], 'FlyingPress disable_method must be null post-reclass' );
        $this->assertNull( $fp['warning'],        'FlyingPress warning copy must be null post-reclass' );
    }
}

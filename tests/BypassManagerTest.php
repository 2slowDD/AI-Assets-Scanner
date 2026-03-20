<?php
// tests/BypassManagerTest.php
namespace CUScanner\Tests;

use CUScanner\Scanner\BypassManager;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class BypassManagerTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_create_token_sets_transient(): void {
        WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturn( 'test-uuid-1234' );
        WP_Mock::userFunction( 'set_transient' )
            ->with( 'cu_scan_token_test-uuid-1234', 1, \Mockery::type( 'int' ) )
            ->once();
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_active_tokens', [] )
            ->andReturn( [] );
        WP_Mock::userFunction( 'update_option' )->once();

        $token = ( new BypassManager() )->create_token();
        $this->assertSame( 'test-uuid-1234', $token );
        $this->assertConditionsMet();
    }

    public function test_delete_all_tokens_clears_transients(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_active_tokens', [] )
            ->andReturn( [ 'tok-a', 'tok-b' ] );
        WP_Mock::userFunction( 'delete_transient' )->twice();
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_active_tokens', [] )
            ->once();
        ( new BypassManager() )->delete_all_tokens();
        $this->assertConditionsMet();
    }

    public function test_build_url_appends_token_and_bypass_params(): void {
        WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
            fn( $params, $url ) => $url . '?' . http_build_query( $params )
        );

        $manager = new BypassManager();
        $url = $manager->build_url(
            'https://site.com/about/',
            'tok-abc',
            [ 'nowpcu' => '', 'nowprocket' => '' ]
        );
        $this->assertStringContainsString( 'cu_scan_token=tok-abc', $url );
        $this->assertStringContainsString( 'nowpcu', $url );
        $this->assertStringContainsString( 'nowprocket', $url );
    }

    public function test_validate_token_returns_true_for_valid_transient(): void {
        WP_Mock::userFunction( 'get_transient' )
            ->with( 'cu_scan_token_valid-token' )
            ->andReturn( 1 );
        $this->assertTrue( ( new BypassManager() )->is_valid_token( 'valid-token' ) );
    }

    public function test_validate_token_returns_false_for_missing_transient(): void {
        WP_Mock::userFunction( 'get_transient' )->andReturn( false );
        $this->assertFalse( ( new BypassManager() )->is_valid_token( 'bad-token' ) );
    }
}

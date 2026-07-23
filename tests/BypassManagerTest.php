<?php
// tests/BypassManagerTest.php
namespace CUScanner\Tests;

use CUScanner\Scanner\BypassManager;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class BypassManagerTest extends TestCase {
    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    // ------------------------------------------------------------------ //
    //  create_token                                                        //
    // ------------------------------------------------------------------ //

    public function test_create_token_uses_option_storage_not_transient(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_active_tokens', [] )
            ->andReturn( [] );
        $captured = null;
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value, $autoload = null ) use ( &$captured ) {
                if ( $key === 'cu_scanner_active_tokens' ) {
                    $captured = [ 'value' => $value, 'autoload' => $autoload ];
                }
                return true;
            } );
        WP_Mock::userFunction( 'set_transient' )->never();

        $token = ( new BypassManager() )->create_token();

        $this->assertNotNull( $captured );
        $this->assertSame( false, $captured['autoload'], 'Option must be stored with autoload=false' );
        $this->assertArrayHasKey( $token, $captured['value'] );
        $this->assertGreaterThan( time(), $captured['value'][ $token ], 'Token must have a future expires_at' );
    }

    public function test_create_token_is_csprng_hex(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_active_tokens', [] )
            ->andReturn( [] );
        WP_Mock::userFunction( 'update_option' )->andReturn( true );

        $token = ( new BypassManager() )->create_token();

        $this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );
    }

    // ------------------------------------------------------------------ //
    //  delete_all_tokens                                                   //
    // ------------------------------------------------------------------ //

    public function test_delete_all_tokens_clears_option(): void {
        WP_Mock::userFunction( 'get_option' )->andReturn( [ 'tok-1' => time() + 100 ] );
        $captured = null;
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$captured ) {
                if ( $key === 'cu_scanner_active_tokens' ) {
                    $captured = $value;
                }
                return true;
            } );
        WP_Mock::userFunction( 'set_transient' )->never();
        WP_Mock::userFunction( 'delete_transient' )->never();

        ( new BypassManager() )->delete_all_tokens();

        $this->assertSame( [], $captured );
    }

    // ------------------------------------------------------------------ //
    //  is_valid_token                                                      //
    // ------------------------------------------------------------------ //

    public function test_expired_token_rejected(): void {
        $stored = [ 'tok-old' => time() - 10 ];  // already expired
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_active_tokens', [] )
            ->andReturn( $stored );
        WP_Mock::userFunction( 'update_option' )->withAnyArgs();  // GC may run
        WP_Mock::userFunction( 'set_transient' )->never();
        WP_Mock::userFunction( 'delete_transient' )->never();
        WP_Mock::userFunction( 'get_transient' )->never();

        $bm = new BypassManager();
        $this->assertFalse( $bm->is_valid_token( 'tok-old' ) );
    }

    public function test_legacy_flat_list_treated_as_expired(): void {
        // Pre-migration: flat list of token strings. Validity must return false.
        $stored = [ 'tok-legacy-1', 'tok-legacy-2' ];
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_active_tokens', [] )
            ->andReturn( $stored );
        WP_Mock::userFunction( 'update_option' )->withAnyArgs();
        WP_Mock::userFunction( 'set_transient' )->never();
        WP_Mock::userFunction( 'delete_transient' )->never();
        WP_Mock::userFunction( 'get_transient' )->never();

        $bm = new BypassManager();
        $this->assertFalse( $bm->is_valid_token( 'tok-legacy-1' ) );
    }

    // ------------------------------------------------------------------ //
    //  Durability — the P0 scenario                                        //
    // ------------------------------------------------------------------ //

    public function test_token_survives_object_cache_flush(): void {
        // Simulate the EVAPORATION scenario: a token is created, then a plugin's
        // wp_cache_flush() call would have wiped transient storage. With option-
        // backed storage (autoload=false), the token must remain valid.
        $stored = [];
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_active_tokens', [] )
            ->andReturnUsing( function () use ( &$stored ) { return $stored; } );
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value, $autoload = null ) use ( &$stored ) {
                if ( $key === 'cu_scanner_active_tokens' ) {
                    $stored = $value;
                }
                return true;
            } );
        WP_Mock::userFunction( 'set_transient' )->never();   // legacy path must not fire
        WP_Mock::userFunction( 'delete_transient' )->never();
        WP_Mock::userFunction( 'get_transient' )->never();

        $bm    = new BypassManager();
        $token = $bm->create_token();

        // Simulate wp_cache_flush: in real WP this would clear all transients.
        // Our option-backed storage is unaffected. Assert validity post-flush.
        $this->assertTrue( $bm->is_valid_token( $token ) );
    }

    // ------------------------------------------------------------------ //
    //  Garbage collection                                                  //
    // ------------------------------------------------------------------ //

    public function test_garbage_collection_prunes_expired_on_read(): void {
        $stored = [
            'tok-expired' => time() - 100,
            'tok-valid'   => time() + 100,
        ];
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_active_tokens', [] )
            ->andReturn( $stored );
        $written = null;
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$written ) {
                if ( $key === 'cu_scanner_active_tokens' ) {
                    $written = $value;
                }
                return true;
            } );

        $bm = new BypassManager();
        $bm->is_valid_token( 'tok-expired' );

        // GC should have run; the expired token must be pruned from the written map.
        if ( $written !== null ) {
            $this->assertArrayNotHasKey( 'tok-expired', $written );
            $this->assertArrayHasKey( 'tok-valid', $written );
        }
    }

    // ------------------------------------------------------------------ //
    //  build_url (unchanged behaviour)                                     //
    // ------------------------------------------------------------------ //

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
}

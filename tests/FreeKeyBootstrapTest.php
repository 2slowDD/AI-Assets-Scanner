<?php
namespace CUScanner\Tests;

use CUScanner\FreeKeyBootstrap;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class FreeKeyBootstrapTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_bootstrap_keeps_existing_paid_key(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( 'cusk_paid_random' );
        WP_Mock::userFunction( 'update_option' )->never();

        $bootstrap = new FreeKeyBootstrap( null, function (): object {
            throw new \RuntimeException( 'Client should not be created' );
        } );

        $bootstrap->run();
        $this->assertTrue( true );
    }

    public function test_bootstrap_stores_returned_free_key_for_empty_install(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( '' );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_api_key', 'cusk_Freekey_10' )
            ->once();
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'cu_scanner_free_key_pending' )
            ->once();

        $bootstrap = new FreeKeyBootstrap( null, function (): object {
            return new class {
                public function register_free_key( string $current ): array {
                    return [ 'api_key' => 'cusk_Freekey_10', 'balance' => 3, 'status' => 'active' ];
                }
            };
        } );

        $bootstrap->run();
        $this->assertTrue( true );
    }

    public function test_bootstrap_caches_railway_url_after_free_key_activation(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( '' );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_api_key', 'cusk_Freekey_10' )
            ->once();
        WP_Mock::userFunction( 'delete_option' )
            ->with( 'cu_scanner_free_key_pending' )
            ->once();
        WP_Mock::userFunction( 'wp_parse_url' )
            ->andReturnUsing( function ( string $url, ?int $component = null ) {
                $parts = parse_url( $url );
                if ( null === $component ) {
                    return $parts;
                }
                $map = [
                    PHP_URL_SCHEME => 'scheme',
                    PHP_URL_HOST   => 'host',
                    PHP_URL_PORT   => 'port',
                    PHP_URL_USER   => 'user',
                    PHP_URL_PASS   => 'pass',
                ];
                return $parts[ $map[ $component ] ?? '' ] ?? null;
            } );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_railway_url', 'https://cu-scanner-railway-production.up.railway.app' )
            ->once();

        $bootstrap = new FreeKeyBootstrap( null, function ( string $current_key ): object {
            if ( 'cusk_Freekey_10' === $current_key ) {
                return new class {
                    public function authenticate(): array {
                        return [ 'railway_url' => 'https://cu-scanner-railway-production.up.railway.app' ];
                    }
                };
            }

            return new class {
                public function register_free_key( string $current ): array {
                    return [ 'api_key' => 'cusk_Freekey_10', 'balance' => 3, 'status' => 'active' ];
                }
            };
        } );

        $bootstrap->run();
        $this->assertTrue( true );
    }

    public function test_bootstrap_sets_pending_placeholder_when_saas_unreachable(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )
            ->andReturn( '' );
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_api_key', 'cusk_Freekey_?' )
            ->once();
        WP_Mock::userFunction( 'update_option' )
            ->with( 'cu_scanner_free_key_pending', '1', false )
            ->once();

        $bootstrap = new FreeKeyBootstrap( null, function (): object {
            return new class {
                public function register_free_key( string $current ): array {
                    throw new \RuntimeException( 'offline' );
                }
            };
        } );

        $bootstrap->run();
        $this->assertTrue( true );
    }
}

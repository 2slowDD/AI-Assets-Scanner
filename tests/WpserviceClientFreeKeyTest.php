<?php
namespace CUScanner\Tests;

use CUScanner\Api\WpserviceClient;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class WpserviceClientFreeKeyTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_register_free_key_posts_domain_and_current_key(): void {
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://www.Example.com/site' );
        WP_Mock::userFunction( 'wp_remote_post' )
            ->with( 'https://api.wpservice.pro/cu-scanner/v1/free-key/register', \Mockery::on( function ( array $args ): bool {
                $body = json_decode( $args['body'], true );
                return 'example.com' === $body['domain']
                    && 'cusk_Freekey_?' === $body['current_api_key'];
            } ) )
            ->once()
            ->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [
            'api_key' => 'cusk_Freekey_9',
            'balance' => 3,
            'status'  => 'active',
        ] ) );

        $client = new WpserviceClient( 'https://api.wpservice.pro', 'cusk_Freekey_?' );
        $result = $client->register_free_key( 'cusk_Freekey_?' );

        $this->assertSame( 'cusk_Freekey_9', $result['api_key'] );
    }

    public function test_claim_paid_key_posts_domain_current_key_and_claim_token(): void {
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://www.Example.com/site' );
        WP_Mock::userFunction( 'wp_remote_post' )
            ->with( 'https://api.wpservice.pro/cu-scanner/v1/free-key/claim-paid', \Mockery::on( function ( array $args ): bool {
                $body = json_decode( $args['body'], true );
                return 'example.com' === $body['domain']
                    && 'cusk_Freekey_12' === $body['current_api_key']
                    && str_repeat( 'a', 64 ) === $body['claim_token'];
            } ) )
            ->once()
            ->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [
            'api_key' => 'cusk_paid_autoclaim',
            'claimed' => true,
        ] ) );

        $client = new WpserviceClient( 'https://api.wpservice.pro', 'cusk_Freekey_12' );
        $result = $client->claim_paid_key( 'cusk_Freekey_12', str_repeat( 'a', 64 ) );

        $this->assertSame( 'cusk_paid_autoclaim', $result['api_key'] );
    }
}

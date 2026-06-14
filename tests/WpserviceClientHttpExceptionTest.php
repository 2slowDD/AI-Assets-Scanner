<?php
namespace CUScanner\Tests;

use CUScanner\Api\WpserviceClient;
use CUScanner\Api\HttpException;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class WpserviceClientHttpExceptionTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }
    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_503_throws_http_exception_with_status_503(): void {
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://s.test' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
        WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [ 'response' => [ 'code' => 503 ] ] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 503 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{"message":"queue_full"}' );
        try {
            ( new WpserviceClient( 'https://api.test', 'k' ) )->reserve_job( 3 );
            $this->fail( 'expected HttpException' );
        } catch ( HttpException $e ) {
            $this->assertSame( 503, $e->get_status_code() );
        }
    }

    public function test_wp_error_throws_http_exception_with_status_0(): void {
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://s.test' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
        WP_Mock::userFunction( 'wp_remote_post' )->andReturn( new \WP_Error( 'http', 'Connection refused' ) );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( true );
        try {
            ( new WpserviceClient( 'https://api.test', 'k' ) )->reserve_job( 3 );
            $this->fail( 'expected HttpException' );
        } catch ( HttpException $e ) {
            $this->assertSame( 0, $e->get_status_code() );
        }
    }

    public function test_402_still_throws_and_is_caught_as_runtime_exception(): void {
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://s.test' );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
        WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [ 'response' => [ 'code' => 402 ] ] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 402 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{"message":"Insufficient credits"}' );
        $this->expectException( \RuntimeException::class );
        ( new WpserviceClient( 'https://api.test', 'k' ) )->reserve_job( 3 );
    }
}

<?php
namespace CUScanner\Tests;

use CUScanner\Api\RailwayClient;
use CUScanner\Api\HttpException;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class RailwayClientHttpExceptionTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
    }
    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_500_throws_http_exception_with_status_500(): void {
        WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $v ) => json_encode( $v ) );
        WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [ 'response' => [ 'code' => 500 ] ] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 500 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{"error":"boom"}' );
        try {
            ( new RailwayClient( 'https://cu-scanner-railway-production.up.railway.app', 'k' ) )
                ->submit_job( [ 'job_token' => 't' ] );
            $this->fail( 'expected HttpException' );
        } catch ( HttpException $e ) {
            $this->assertSame( 500, $e->get_status_code() );
        }
    }
}

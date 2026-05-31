<?php
// tests/WpserviceClientTest.php
namespace CUScanner\Tests;

use CUScanner\Api\WpserviceClient;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class WpserviceClientTest extends TestCase {
    private WpserviceClient $client;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( 'get_home_url', [ 'return' => 'https://example.com' ] );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
        $this->client = new WpserviceClient( 'https://wpservice.pro/wp-json', 'sk-test' );
    }
    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_authenticate_returns_parsed_response(): void {
        WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [
            'user_id'      => 42,
            'credits'      => 500,
            'railway_url'  => 'https://cu-scanner-railway-production.up.railway.app',
        ] ) ] );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [
            'user_id' => 42, 'credits' => 500, 'railway_url' => 'https://cu-scanner-railway-production.up.railway.app',
        ] ) );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );

        $result = $this->client->authenticate();
        $this->assertSame( 42, $result['user_id'] );
        $this->assertSame( 500, $result['credits'] );
        $this->assertSame( 'https://cu-scanner-railway-production.up.railway.app', $result['railway_url'] );
    }

    public function test_authenticate_throws_on_wp_error(): void {
        $err = new \WP_Error( 'http_error', 'Failed' );
        WP_Mock::userFunction( 'wp_remote_post' )->andReturn( $err );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( true );
        $this->expectException( \RuntimeException::class );
        $this->client->authenticate();
    }

    public function test_reserve_job_returns_job_token(): void {
        WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [ 'job_token' => 'tok-abc' ] ) );

        $result = $this->client->reserve_job( 10 );
        $this->assertSame( 'tok-abc', $result['job_token'] );
    }

    public function test_reserve_job_forwards_extra_time_count_in_post_body(): void {
        // FU-AAS-EXTRA-TIME — the AAS middle-hop must forward extra_time_count
        // (the "M" of the N+M reserve gate) to the SaaS /jobs/reserve endpoint.
        $captured = null;
        WP_Mock::userFunction( 'wp_remote_post' )
            ->with( 'https://wpservice.pro/wp-json/cu-scanner/v1/jobs/reserve', \Mockery::on( function ( array $args ) use ( &$captured ): bool {
                $captured = json_decode( $args['body'], true );
                return true;
            } ) )
            ->once()
            ->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [ 'job_token' => 'tok-et' ] ) );

        $result = $this->client->reserve_job( 7, 3 );

        $this->assertSame( 'tok-et', $result['job_token'] );
        $this->assertSame( 7, $captured['page_count'] );
        $this->assertArrayHasKey( 'extra_time_count', $captured );
        $this->assertSame( 3, $captured['extra_time_count'] );
    }

    public function test_reserve_job_defaults_extra_time_count_to_zero_when_omitted(): void {
        // Backward-compat: callers that omit the ET arg must produce a 0, so the
        // SaaS N+M gate behaves identically to the legacy N-only path.
        $captured = null;
        WP_Mock::userFunction( 'wp_remote_post' )
            ->with( 'https://wpservice.pro/wp-json/cu-scanner/v1/jobs/reserve', \Mockery::on( function ( array $args ) use ( &$captured ): bool {
                $captured = json_decode( $args['body'], true );
                return true;
            } ) )
            ->once()
            ->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [ 'job_token' => 'tok-default' ] ) );

        $this->client->reserve_job( 10 );

        $this->assertSame( 0, $captured['extra_time_count'] );
    }

    public function test_reserve_job_throws_on_402(): void {
        WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 402 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [ 'message' => 'Insufficient credits' ] ) );
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Insufficient credits' );
        $this->client->reserve_job( 10 );
    }

    public function test_release_credits_calls_correct_endpoint(): void {
        WP_Mock::userFunction( 'wp_remote_post' )
            ->with( \Mockery::type( 'string' ), \Mockery::type( 'array' ) )
            ->once()
            ->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{}' );
        $this->client->release_credits( 'tok-abc' );
        $this->assertConditionsMet();
    }
}

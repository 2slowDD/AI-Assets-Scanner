<?php
// tests/RailwayClientTest.php
namespace CUScanner\Tests;

use CUScanner\Api\RailwayClient;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class RailwayClientTest extends TestCase {
    private RailwayClient $client;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->client = new RailwayClient( 'https://railway.example.com', 'api-key-123' );
    }
    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_submit_job_returns_job_id(): void {
        WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [ 'job_id' => 'job-xyz' ] ) );

        $result = $this->client->submit_job( [
            'urls'          => [ 'https://site.com/' ],
            'job_token'     => 'tok-abc',
            'api_key'       => 'api-key-123',
            'wpservice_url' => 'https://wpservice.pro/wp-json',
        ] );
        $this->assertSame( 'job-xyz', $result['job_id'] );
    }

    public function test_get_status_uses_from_param(): void {
        WP_Mock::userFunction( 'wp_remote_get' )
            ->with( 'https://railway.example.com/jobs/job-xyz/status?from=5', \Mockery::type( 'array' ) )
            ->once()
            ->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [
            'status' => 'in_progress', 'total' => 10, 'completed' => 5, 'pages' => [],
        ] ) );

        $result = $this->client->get_status( 'job-xyz', 'tok-abc', 5 );
        $this->assertSame( 'in_progress', $result['status'] );
        $this->assertConditionsMet();
    }

    public function test_get_status_throws_on_410_gone(): void {
        WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 410 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{}' );
        $this->expectException( \RuntimeException::class );
        $this->client->get_status( 'job-xyz', 'tok-abc', 0 );
    }

    public function test_cancel_job_posts_to_correct_endpoint(): void {
        WP_Mock::userFunction( 'wp_remote_post' )
            ->with( 'https://railway.example.com/jobs/job-xyz/cancel', \Mockery::type( 'array' ) )
            ->once()
            ->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{}' );

        $this->client->cancel_job( 'job-xyz', 'tok-abc' );
        $this->assertConditionsMet();
    }
}

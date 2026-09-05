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
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
        $this->client = new RailwayClient( 'https://cu-scanner-railway-production.up.railway.app', 'api-key-123' );
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
            'pages'         => [ [ 'url' => 'https://site.com/', 'bypass_token' => 'tok-xyz' ] ],
            'job_token'     => 'tok-abc',
            'api_key'       => 'api-key-123',
            'wpservice_url' => 'https://wpservice.pro',
        ] );
        $this->assertSame( 'job-xyz', $result['job_id'] );
    }

    public function test_get_status_uses_from_param(): void {
        WP_Mock::userFunction( 'wp_remote_get' )
            ->with( 'https://cu-scanner-railway-production.up.railway.app/jobs/job-xyz/status?from=5', \Mockery::type( 'array' ) )
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
            ->with( 'https://cu-scanner-railway-production.up.railway.app/jobs/job-xyz/cancel', \Mockery::type( 'array' ) )
            ->once()
            ->andReturn( [] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{}' );

        $this->client->cancel_job( 'job-xyz', 'tok-abc' );
        $this->assertConditionsMet();
    }

    /**
     * FU-AAS-VERSION-IN-SCAN-LOG — AC-A1. The REAL submit_job posts plugin_version = CU_SCANNER_VERSION
     * in the JSON body. Assert the CONSTANT: tests/bootstrap.php defines it as '1.0.0' and
     * VersionLockstepTest pins that shadow — a literal here would be a lockstep assertion built on a fixture.
     */
    public function test_submit_job_body_carries_plugin_version_equal_to_the_constant(): void {
        $captured = null;
        // Not mocked in the other tests in this file because they never decode the outgoing
        // body; WP_Mock has no built-in passthru, so an unmocked call returns null (see
        // RailwayClientHttpExceptionTest.php:21 for the same pattern on the same function).
        WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( fn( $v ) => json_encode( $v ) );
        WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing( function ( $url, $args ) use ( &$captured ) {
            $captured = [ 'url' => $url, 'args' => $args ];
            return [];
        } );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( json_encode( [ 'job_id' => 'job-xyz' ] ) );

        $payload = [
            'pages'         => [ [ 'url' => 'https://site.test/', 'bypass_token' => 'tok-xyz' ] ],
            'job_token'     => 'tok-abc',
            'api_key'       => 'api-key-123',
            'wpservice_url' => 'https://wpservice.pro',
        ];
        $result = $this->client->submit_job( $payload );

        $this->assertSame( 'job-xyz', $result['job_id'] );
        $this->assertNotNull( $captured, 'wp_remote_post was not called' );
        $this->assertSame( 'https://cu-scanner-railway-production.up.railway.app/jobs', $captured['url'] );
        $body = json_decode( (string) $captured['args']['body'], true );
        $this->assertIsArray( $body );
        $this->assertArrayHasKey( 'plugin_version', $body );
        $this->assertSame( CU_SCANNER_VERSION, $body['plugin_version'] );
        $this->assertSame( 'tok-abc', $body['job_token'] );
        $this->assertSame( $payload['pages'], $body['pages'] );
        $this->assertSame( 'Bearer tok-abc', $captured['args']['headers']['Authorization'] );
        // by-value: the caller's array is untouched
        $this->assertArrayNotHasKey( 'plugin_version', $payload );
    }
}

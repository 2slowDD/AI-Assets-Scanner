<?php
use PHPUnit\Framework\TestCase;
use CUScanner\Api\RailwayClient;

class Test_Railway_Client_Job_Token extends TestCase {

    public function test_submit_job_sends_job_token_in_header_not_api_key() {
        $captured = null;
        add_filter( 'pre_http_request', function( $pre, $args, $url ) use ( &$captured ) {
            $captured = $args;
            return [
                'response' => [ 'code' => 200, 'message' => 'OK' ],
                'body'     => '{"job_id":"abc-123"}',
                'headers'  => [],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3 );

        $client = new RailwayClient( 'https://railway.test', 'cusk_APIKEY_SHOULD_NOT_LEAK' );
        $client->submit_job( [
            'job_token' => 'jobtok_abc',
            'pages'     => [ [ 'url' => 'https://example.com' ] ],
        ] );

        remove_all_filters( 'pre_http_request' );

        $this->assertIsArray( $captured, 'pre_http_request never fired' );
        $this->assertStringContainsString(
            'Bearer jobtok_abc',
            $captured['headers']['Authorization'],
            'Authorization header must carry the job_token'
        );
        $this->assertStringNotContainsString(
            'cusk_',
            $captured['headers']['Authorization'],
            'api_key must not appear in the Authorization header'
        );

        $body = json_decode( $captured['body'], true );
        $this->assertArrayNotHasKey( 'api_key', $body, 'api_key must not be sent in the body' );
        $this->assertSame( 'jobtok_abc', $body['job_token'] );
    }

    public function test_submit_job_throws_when_job_token_missing() {
        $this->expectException( \RuntimeException::class );
        $client = new RailwayClient( 'https://railway.test', 'cusk_APIKEY' );
        $client->submit_job( [ 'pages' => [] ] );
    }
}

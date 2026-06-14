<?php
namespace CUScanner\Tests;

use CUScanner\Api\HttpException;
use WP_Mock\Tools\TestCase;

class HttpExceptionTest extends TestCase {
    public function test_carries_status_code_and_is_a_runtime_exception(): void {
        $e = new HttpException( 'HTTP 503: queue_full', 503 );
        $this->assertInstanceOf( \RuntimeException::class, $e );
        $this->assertSame( 503, $e->get_status_code() );
        $this->assertSame( 'HTTP 503: queue_full', $e->getMessage() );
    }

    public function test_zero_status_means_network_or_wp_error(): void {
        $e = new HttpException( 'Connection refused', 0 );
        $this->assertSame( 0, $e->get_status_code() );
    }
}

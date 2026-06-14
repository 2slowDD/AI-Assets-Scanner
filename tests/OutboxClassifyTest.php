<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\Outbox;
use CUScanner\Api\HttpException;
use WP_Mock\Tools\TestCase;

class OutboxClassifyTest extends TestCase {
    public function retryable_cases(): array {
        return [ 'network' => [0, true], '500' => [500, true], '503' => [503, true], '599' => [599, true] ];
    }
    public function terminal_cases(): array {
        return [ '402' => [402], '400' => [400], '409' => [409], '429' => [429], '410' => [410] ];
    }
    /** @dataProvider retryable_cases */
    public function test_retryable( int $code, bool $expected ): void {
        $this->assertSame( $expected, Outbox::is_retryable( new HttpException( 'x', $code ) ) );
    }
    /** @dataProvider terminal_cases */
    public function test_terminal( int $code ): void {
        $this->assertFalse( Outbox::is_retryable( new HttpException( 'x', $code ) ) );
    }
    public function test_untyped_throwable_is_terminal(): void {
        $this->assertFalse( Outbox::is_retryable( new \RuntimeException( 'plain' ) ) );
    }
}

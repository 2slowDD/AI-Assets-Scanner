<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\StrategyFactory;

class StrategyFactoryTest extends \PHPUnit\Framework\TestCase {

    public function test_for_method_flying_press_throws_after_reclass(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessageMatches( '/Unknown disable_method: flying_press/' );
        StrategyFactory::for_method( 'flying_press' );
    }

    public function test_for_method_unknown_throws(): void {
        $this->expectException( \InvalidArgumentException::class );
        StrategyFactory::for_method( 'no_such_optimizer' );
    }
}

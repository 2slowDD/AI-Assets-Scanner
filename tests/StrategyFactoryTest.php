<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\StrategyFactory;
use CUScanner\Scanner\Strategies\FlyingPressBypass;

class StrategyFactoryTest extends \PHPUnit\Framework\TestCase {

    public function test_for_method_flying_press_returns_flying_press_strategy(): void {
        $strategy = StrategyFactory::for_method( 'flying_press' );
        $this->assertInstanceOf( FlyingPressBypass::class, $strategy );
        $this->assertSame( 'flying_press', $strategy->slug() );
    }

    public function test_for_method_unknown_throws(): void {
        $this->expectException( \InvalidArgumentException::class );
        StrategyFactory::for_method( 'no_such_optimizer' );
    }
}

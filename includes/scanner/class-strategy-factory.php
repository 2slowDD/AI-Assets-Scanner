<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

use CUScanner\Scanner\Strategies\AbstractOptimizerBypass;
use CUScanner\Scanner\Strategies\FlyingPressBypass;

class StrategyFactory {
    public static function for_method( string $method ): AbstractOptimizerBypass {
        return match ( $method ) {
            'flying_press' => new FlyingPressBypass(),
            // 'sg_optimizer' and 'hummingbird' added in Phase 4.
            default => throw new \InvalidArgumentException( "Unknown disable_method: {$method}" ),
        };
    }
}

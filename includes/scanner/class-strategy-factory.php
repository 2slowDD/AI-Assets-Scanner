<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

use CUScanner\Scanner\Strategies\AbstractOptimizerBypass;
use CUScanner\Scanner\Strategies\FlyingPressBypass;
use CUScanner\Scanner\Strategies\SgOptimizerBypass;
use CUScanner\Scanner\Strategies\HummingbirdBypass;

class StrategyFactory {
    public static function for_method( string $method ): AbstractOptimizerBypass {
        return match ( $method ) {
            'flying_press' => new FlyingPressBypass(),
            'sg_optimizer' => new SgOptimizerBypass(),
            'hummingbird'  => new HummingbirdBypass(),
            default => throw new \InvalidArgumentException( "Unknown disable_method: {$method}" ),
        };
    }
}

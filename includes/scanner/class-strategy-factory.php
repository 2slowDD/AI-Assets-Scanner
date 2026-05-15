<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

use CUScanner\Scanner\Strategies\AbstractOptimizerBypass;
use CUScanner\Scanner\Strategies\SgOptimizerBypass;
use CUScanner\Scanner\Strategies\HummingbirdBypass;

class StrategyFactory {
    public static function for_method( string $method ): AbstractOptimizerBypass {
        return match ( $method ) {
            'sg_optimizer' => new SgOptimizerBypass(),
            'hummingbird'  => new HummingbirdBypass(),
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message thrown for caller to handle (logging or wp_send_json_error), not echoed.
            default => throw new \InvalidArgumentException( "Unknown disable_method: {$method}" ),
        };
    }
}

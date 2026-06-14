<?php
namespace CUScanner\Scanner;

use CUScanner\Api\HttpException;

defined( 'ABSPATH' ) || exit;

class Outbox {
    public const OPTION_KEY   = 'cu_scanner_outbox';
    public const LOCK_KEY     = 'cu_scanner_outbox_lock';
    public const CRON_HOOK    = 'cu_scanner_outbox_replay';

    public const BASE_BACKOFF = 30;
    public const MAX_BACKOFF  = 3600;
    public const HORIZON      = 86400;
    public const MAX_ATTEMPTS = 50;
    public const LOCK_TTL     = 120;

    /** Retryable iff the failure is an HttpException with a network(0) or 5xx status. */
    public static function is_retryable( \Throwable $e ): bool {
        if ( ! $e instanceof HttpException ) {
            return false;
        }
        $code = $e->get_status_code();
        return $code === 0 || ( $code >= 500 && $code <= 599 ); // 5xx incl. the 503 soft-cap (queue_full)
    }
}

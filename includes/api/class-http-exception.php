<?php
namespace CUScanner\Api;

defined( 'ABSPATH' ) || exit;

/**
 * RuntimeException subclass that carries the HTTP status code of a failed
 * API call (0 = is_wp_error / network / timeout). Existing
 * `catch ( \RuntimeException )` sites are unaffected — this IS a RuntimeException.
 */
class HttpException extends \RuntimeException {
    public function __construct( string $message, private int $status_code = 0 ) {
        parent::__construct( $message );
    }

    public function get_status_code(): int {
        return $this->status_code;
    }
}

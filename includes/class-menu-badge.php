<?php
namespace CUScanner;

defined( 'ABSPATH' ) || exit;

class MenuBadge {

    private const OPTION_LAST_SEEN = 'aias_last_seen_scan_id';

    // Badge state values (returned by get_badge_state + on Heartbeat wire).
    private const BADGE_STATE_GREEN = 'green';
    private const BADGE_STATE_RED   = 'red';

    // Production status strings — verified against admin/class-scanner-ajax.php writes.
    // 'queued' L387 (initial, non-terminal) | 'cancelled' L507+L650 (terminal, non-triggering)
    // 'complete' L547 (success → green) | 'failed' L687 (error → red)
    private const STATUS_COMPLETE  = 'complete';
    private const STATUS_FAILED    = 'failed';
    private const STATUS_CANCELLED = 'cancelled';

    /** @var ScanHistory|null Constructor-injected for testability (per d-review Minor 5). */
    private $history;

    public function __construct( ?ScanHistory $history = null ) {
        $this->history = $history;   // null => lazy-init in get_history() (production path).
    }

    public function get_badge_state(): ?string {
        return null;   // Task 1 stub — real logic in Task 2.
    }
}

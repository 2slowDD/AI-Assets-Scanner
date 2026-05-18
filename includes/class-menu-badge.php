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

    /**
     * Determine current badge state.
     * Returns 'red' if most-recent badge-triggering terminal scan is failed-and-unseen.
     * Returns 'green' if most-recent badge-triggering terminal scan is complete-and-unseen.
     * Returns null if no badge-triggering terminal scan exists, or it has been seen.
     *
     * "Badge-triggering terminal" = status in {'complete', 'failed'}.
     * Excludes 'queued' (not terminal) and 'cancelled' (terminal but operator-dismissed).
     */
    public function get_badge_state(): ?string {
        $latest_rec = $this->most_recent_triggering_record( $this->get_history()->get_all() );
        if ( $latest_rec === null ) {
            return null;
        }

        $latest_seen = (string) get_option( self::OPTION_LAST_SEEN, '' );
        if ( $latest_rec['job_id'] === $latest_seen ) {
            return null;
        }

        return $latest_rec['status'] === self::STATUS_FAILED
            ? self::BADGE_STATE_RED
            : self::BADGE_STATE_GREEN;
    }

    /**
     * Returns the most-recent BADGE-TRIGGERING terminal record, walking newest-first.
     * 'complete' and 'failed' trigger the badge; 'cancelled' and 'queued' are skipped.
     */
    private function most_recent_triggering_record( array $history ): ?array {
        foreach ( $history as $rec ) {
            $status = $rec['status'] ?? '';
            if ( $status === self::STATUS_COMPLETE || $status === self::STATUS_FAILED ) {
                return $rec;
            }
        }
        return null;
    }

    private function get_history(): ScanHistory {
        if ( $this->history === null ) {
            $this->history = new ScanHistory();
        }
        return $this->history;
    }
}

<?php
// A2c Step 0 — build_pages() row field `kept_protection`, the passthrough the per-row
// "kept" chip renders on (admin/js/scanner.js, .cu-kept-chip).
//
// WHY THIS FILE EXISTS: aggregate_kept_protection() reads $pages_raw directly for the
// scan-level note, so the scan-level half works with or without this key. The per-row half
// does not: without the key, p.kept_protection is undefined client-side and the chip renders
// NEVER, silently, behind a green JS suite (the JS tests feed row fixtures directly and
// cannot see this seam). This file is the only thing standing between that and production.
//
// Defensive-validated per the bypass_suffixes / visual_channel_off convention — $page is
// built from untrusted Railway response data.
use PHPUnit\Framework\TestCase;

final class ScanStatusKeptProtectionTest extends TestCase {
    private function page( array $o ): array {
        return array_merge( [ 'url' => 'https://example.test/', 'status' => 'done', 'assets' => [], 'broken_devices' => [] ], $o );
    }

    private function rows( $kept ): array {
        $extra = ( func_num_args() > 0 && null !== $kept ) ? [ 'kept_protection' => $kept ] : [];
        return AIAS_Scan_Status::build_pages( [ $this->page( $extra ) ], [] );
    }

    /** The key must exist on EVERY row — its absence is the whole defect this pins. */
    public function test_key_is_always_present_on_the_row(): void {
        $rows = AIAS_Scan_Status::build_pages( [ $this->page( [] ) ], [] );
        $this->assertArrayHasKey( 'kept_protection', $rows[0] );
    }

    public function test_valid_entries_pass_through(): void {
        $kept = [ [ 'display_name' => 'Cloudflare', 'handles' => [ 'cf-challenge|script' ] ] ];
        $rows = $this->rows( $kept );
        $this->assertSame( $kept, $rows[0]['kept_protection'] );
    }

    public function test_multiple_entries_pass_through_in_order(): void {
        $kept = [
            [ 'display_name' => 'Cloudflare', 'handles' => [ 'cf|script' ] ],
            [ 'display_name' => 'Akismet', 'handles' => [ 'ak|script' ] ],
        ];
        $rows = $this->rows( $kept );
        $this->assertSame( $kept, $rows[0]['kept_protection'] );
    }

    public function test_key_absent_legacy_row_yields_empty(): void {
        // A scan that predates the worker wire field entirely — the key is never set.
        $rows = AIAS_Scan_Status::build_pages( [ $this->page( [] ) ], [] );
        $this->assertSame( [], $rows[0]['kept_protection'] );
    }

    public function test_empty_array_stays_empty(): void {
        $this->assertSame( [], $this->rows( [] )[0]['kept_protection'] );
    }

    /**
     * @dataProvider junkProvider
     * @param mixed $junk
     */
    public function test_non_array_value_yields_empty( $junk ): void {
        $rows = AIAS_Scan_Status::build_pages( [ $this->page( [ 'kept_protection' => $junk ] ) ], [] );
        $this->assertSame( [], $rows[0]['kept_protection'] );
    }

    public function junkProvider(): array {
        return [
            'string' => [ 'Cloudflare' ],
            'int'    => [ 3 ],
            'float'  => [ 1.5 ],
            'bool'   => [ true ],
            'null'   => [ null ],
            'object' => [ (object) [ 'count' => 2 ] ],
        ];
    }

    public function test_non_array_entries_are_filtered_without_warning(): void {
        $good = [ 'display_name' => 'Cloudflare', 'handles' => [ 'cf|script' ] ];
        $rows = AIAS_Scan_Status::build_pages(
            [ $this->page( [ 'kept_protection' => [ 'junk', 7, null, $good, false ] ] ) ],
            []
        );
        $this->assertSame( [ $good ], $rows[0]['kept_protection'] );
    }

    public function test_all_entries_junk_yields_empty(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ $this->page( [ 'kept_protection' => [ 'junk', 7, null ] ] ) ],
            []
        );
        $this->assertSame( [], $rows[0]['kept_protection'] );
    }

    /**
     * THE LOAD-BEARING ONE. array_filter() PRESERVES keys, so filtering a junk entry out of
     * the MIDDLE leaves [0 => …, 2 => …] — which json_encode emits as a JSON OBJECT, not an
     * array. The client gates the chip on Array.isArray( p.kept_protection ), which is FALSE
     * for an object, so the chip would silently vanish on exactly the rows whose payload had
     * a junk entry in it. array_values() is what keeps the wire shape a JSON array; this
     * test is why it is there.
     */
    public function test_filtered_entries_are_reindexed_so_the_wire_shape_stays_a_json_array(): void {
        $a = [ 'display_name' => 'Cloudflare', 'handles' => [ 'cf|script' ] ];
        $b = [ 'display_name' => 'Akismet', 'handles' => [ 'ak|script' ] ];
        $rows = AIAS_Scan_Status::build_pages(
            [ $this->page( [ 'kept_protection' => [ $a, 'junk-in-the-middle', $b ] ] ) ],
            []
        );
        $this->assertSame( [ 0, 1 ], array_keys( $rows[0]['kept_protection'] ), 'keys must be re-indexed' );
        $this->assertSame( '[', substr( (string) json_encode( $rows[0]['kept_protection'] ), 0, 1 ),
            'must encode as a JSON array — a JSON object fails Array.isArray() client-side and kills the chip' );
    }

    /**
     * Deliberately NOT status-gated, unlike visual_channel_off (which is ok-only because a
     * partial/blocked verdict makes its claim meaningless). "A protection script was found
     * and kept" is a fact about what the scan saw on the page, independent of the verdict —
     * a partial page can genuinely have kept one, and hiding the chip there would understate
     * the scan-level count the A2 note already reports.
     */
    public function test_not_status_gated_partial_row_still_carries_the_field(): void {
        $kept = [ [ 'display_name' => 'Cloudflare', 'handles' => [ 'cf|script' ] ] ];
        $rows = AIAS_Scan_Status::build_pages(
            [ $this->page( [
                'broken_devices'  => [ [ 'device' => 'mobile', 'reason' => 'tier1_http_rate_limit' ] ], // -> 'partial'
                'kept_protection' => $kept,
            ] ) ],
            []
        );
        $this->assertSame( 'partial', $rows[0]['status_class'] );
        $this->assertSame( $kept, $rows[0]['kept_protection'] );
    }

    /** Per-row independence: one page's payload must not leak onto its neighbours. */
    public function test_field_is_per_row_not_shared(): void {
        $kept = [ [ 'display_name' => 'Cloudflare', 'handles' => [ 'cf|script' ] ] ];
        $rows = AIAS_Scan_Status::build_pages(
            [
                $this->page( [ 'url' => 'https://example.test/1', 'kept_protection' => $kept ] ),
                $this->page( [ 'url' => 'https://example.test/2' ] ),
                $this->page( [ 'url' => 'https://example.test/3', 'kept_protection' => [] ] ),
            ],
            []
        );
        $this->assertSame( $kept, $rows[0]['kept_protection'] );
        $this->assertSame( [], $rows[1]['kept_protection'] );
        $this->assertSame( [], $rows[2]['kept_protection'] );
    }
}

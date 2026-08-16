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

    /**
     * Sets kept_protection to EXACTLY $kept — null included. The key-absent case is
     * deliberately NOT expressible through this helper: setting the key to null and omitting
     * it both yield [], so a helper-mediated "absent" test could not tell the two apart and
     * would pass for the wrong reason if the helper itself regressed. The tests that cover
     * key-absent call build_pages() directly, on purpose.
     */
    private function rows( $kept ): array {
        return AIAS_Scan_Status::build_pages( [ $this->page( [ 'kept_protection' => $kept ] ) ], [] );
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

    // -----------------------------------------------------------------------------------
    // A4 F-1 — THE INVARIANT: a row shows a chip only if it contributed at least one handle
    // to the scan-level count. Chip ⊆ note, always.
    //
    // These run BOTH real production predicates — ScannerAjax::aggregate_kept_protection()
    // (the note gate, rendered on count > 0) and AIAS_Scan_Status::build_pages() (which feeds
    // the chip gate) — over the seven payload shapes the A4 audit enumerated, four of which
    // used to produce a shield badge with no note anywhere to explain it.
    //
    // This is the ONLY thing coupling the two predicates: they live in different classes and
    // cannot call each other (dependency direction — ScannerAjax depends on AIAS_Scan_Status,
    // never the reverse), so the coupling is executable rather than structural. If EITHER
    // predicate drifts, these red.
    // -----------------------------------------------------------------------------------

    /**
     * @dataProvider auditPayloadShapeProvider
     * @param mixed $kept_protection The worker's per-page kept_protection value.
     * @param bool  $expect_note     Whether the scan-level note renders (count > 0).
     */
    public function test_chip_and_note_agree_on_every_audited_payload_shape( $kept_protection, bool $expect_note ): void {
        $page = $this->page( [ 'kept_protection' => $kept_protection ] );

        $summary = \CUScanner\Admin\ScannerAjax::aggregate_kept_protection( [ $page ] );
        $note    = $summary['count'] > 0;

        $rows = AIAS_Scan_Status::build_pages( [ $page ], [] );
        $chip = [] !== $rows[0]['kept_protection'];

        $this->assertSame( $expect_note, $note,
            'the NOTE gate moved — it counts distinct non-empty handles and must not change' );
        $this->assertSame( $note, $chip,
            'INVARIANT: a chip may render only where the note renders too (chip is a subset of note)' );
    }

    public function auditPayloadShapeProvider(): array {
        $handle = [ 'display_name' => 'Turnstile', 'handles' => [ 'cf-challenge|script' ] ];
        return [
            // The four shapes the audit found DIVERGENT (chip rendered, note did not).
            'display_name only, no handles'       => [ [ [ 'display_name' => 'Cloudflare Turnstile' ] ], false ],
            'handles = [] (empty list)'           => [ [ [ 'display_name' => 'Turnstile', 'handles' => [] ] ], false ],
            'handles = [""] (empty string)'       => [ [ [ 'display_name' => 'Turnstile', 'handles' => [ '' ] ] ], false ],
            'entry = [] (empty array)'            => [ [ [] ], false ],
            // The three that already agreed — they must stay agreeing.
            'handles present + display_name'      => [ [ $handle ], true ],
            'kept_protection = [] (nothing kept)' => [ [], false ],
            'handles only, no display_name'       => [ [ [ 'handles' => [ 'cf-challenge|script' ] ] ], true ],
        ];
    }

    /**
     * The invariant is per-ROW; the note is per-SCAN. A scan where one page contributed a
     * handle and another carried only a name must render the note AND exactly one chip — on
     * the contributing row. A single-page fixture cannot see this direction: it is what
     * separates "chip ⊆ note" from "any chip anywhere once the note renders".
     */
    public function test_chip_lands_only_on_the_contributing_row_when_the_note_renders(): void {
        $pages = [
            $this->page( [ 'url' => 'https://example.test/1',
                'kept_protection' => [ [ 'display_name' => 'Turnstile', 'handles' => [ 'cf-challenge|script' ] ] ] ] ),
            $this->page( [ 'url' => 'https://example.test/2',
                'kept_protection' => [ [ 'display_name' => 'Cloudflare Turnstile' ] ] ] ),
        ];
        $summary = \CUScanner\Admin\ScannerAjax::aggregate_kept_protection( $pages );
        $rows    = AIAS_Scan_Status::build_pages( $pages, [] );

        $this->assertSame( 1, $summary['count'], 'the note counts the one usable handle' );
        $this->assertNotSame( [], $rows[0]['kept_protection'], 'the contributing row keeps its chip' );
        $this->assertSame( [], $rows[1]['kept_protection'], 'the name-only row must NOT show a chip' );
        // What the row gives up is still surfaced at scan level: the vendor is named in the
        // note even though no chip attributes it to a page. That is the trade this fix makes.
        $this->assertContains( 'Cloudflare Turnstile', $summary['vendors'],
            'the name-only vendor is still listed by the note, just not attributed to a row' );
    }

    // -----------------------------------------------------------------------------------
    // R20 — the chip gate must widen to NON-PROTECTION keeps.
    //
    // Before R20 the only keeps on the wire were protection, so kept_protection alone was the
    // whole truth. The worker now also ships kept_known_assets (Fathom, Stripe, Gravity Forms,
    // wp-core...). A page whose keeps are ENTIRELY non-protection — which is MOST pages — must
    // still show a chip, or the scan-level note counts 9 while no row says where any of them
    // landed. Verified live on 1.7.95b: a scanned page rendered "1 protection script kept"
    // with a single static chip while the worker shipped eight further keeps alongside it.
    //
    // kept_count is the per-row number the chip renders as "N kept". Its unit is the DISTINCT
    // '<handle>|<type>' COMPOSITE — the same unit aggregate_kept_protection() counts in, and
    // deliberately NOT the entry count: one entry can carry two handles (gravity-forms ships
    // gform_gravityforms + gform_json) and the note's own word is "assets".
    // -----------------------------------------------------------------------------------

    private function knownAsset( string $id, string $name, string $category, array $handles ): array {
        return [ 'id' => $id, 'display_name' => $name, 'category' => $category, 'handles' => $handles ];
    }

    /** AC-10 — the whole point: keeps that are entirely non-protection still get a chip. */
    public function test_chip_renders_on_a_page_whose_keeps_are_entirely_non_protection(): void {
        $rows = AIAS_Scan_Status::build_pages( [ $this->page( [
            'kept_known_assets' => [
                $this->knownAsset( 'fathom-analytics', 'Fathom Analytics', 'analytics', [ 'fathom|script' ] ),
                $this->knownAsset( 'stripe-payments', 'Stripe payments', 'payment', [ 'stripe|script' ] ),
            ],
        ] ) ], [] );
        $this->assertSame( 2, $rows[0]['kept_count'],
            'a page with no protection keeps at all must still report a chip count' );
    }

    /** The key must exist on EVERY row — same silent-never-renders defect shape as kept_protection's. */
    public function test_kept_count_key_is_always_present_on_the_row(): void {
        $rows = AIAS_Scan_Status::build_pages( [ $this->page( [] ) ], [] );
        $this->assertArrayHasKey( 'kept_count', $rows[0] );
        $this->assertSame( 0, $rows[0]['kept_count'] );
    }

    /** The unit is composites, not entries. One entry carrying two handles counts 2. */
    public function test_kept_count_counts_composites_not_entries(): void {
        $rows = AIAS_Scan_Status::build_pages( [ $this->page( [
            'kept_known_assets' => [ $this->knownAsset(
                'gravity-forms', 'Gravity Forms', 'form',
                [ 'gform_gravityforms|script', 'gform_json|script' ]
            ) ],
        ] ) ], [] );
        $this->assertSame( 2, $rows[0]['kept_count'] );
    }

    /**
     * Both fields feed ONE count, deduped on the composite — never summed twice. AC-8 says an
     * asset cannot appear in both fields today; counting it twice if that ever changes would
     * inflate a customer-facing number, so the dedupe is pinned rather than assumed.
     */
    public function test_kept_count_spans_both_fields_and_dedupes_the_composite(): void {
        $rows = AIAS_Scan_Status::build_pages( [ $this->page( [
            'kept_protection'   => [ [ 'display_name' => 'Turnstile', 'handles' => [ 'cf-challenge|script' ] ] ],
            'kept_known_assets' => [
                $this->knownAsset( 'fathom-analytics', 'Fathom Analytics', 'analytics', [ 'fathom|script' ] ),
                $this->knownAsset( 'overlap', 'Overlap', 'analytics', [ 'cf-challenge|script' ] ),
            ],
        ] ) ], [] );
        $this->assertSame( 2, $rows[0]['kept_count'] );
    }

    /** Junk from the untrusted worker payload must not fatal or inflate the count (Rule 1). */
    public function test_kept_count_ignores_junk_known_assets(): void {
        $rows = AIAS_Scan_Status::build_pages( [ $this->page( [
            'kept_known_assets' => 'not-an-array',
        ] ) ], [] );
        $this->assertSame( 0, $rows[0]['kept_count'] );
    }
}

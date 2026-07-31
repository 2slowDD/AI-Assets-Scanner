<?php
// FU-VFM-MASKING (spec 2026-07-31 §3.2) — AC-M7a/b/c: build_pages() row field
// `visual_channel_off`, ok-gated (mirrors et_candidate) and whitelisted (mirrors
// bypass_suffixes' defensive-validation convention for untrusted Railway input).
use PHPUnit\Framework\TestCase;

final class ScanStatusChannelOffTest extends TestCase {
    private function page( array $o ): array {
        return array_merge( [ 'url' => 'https://example.test/', 'status' => 'done', 'assets' => [], 'broken_devices' => [] ], $o );
    }

    public function test_valid_single_device_on_ok_row_passes_through(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ $this->page( [ 'visual_channel_off' => [ 'mobile' ] ] ) ],
            []
        );
        $this->assertSame( [ 'mobile' ], $rows[0]['visual_channel_off'] );
    }

    public function test_unknown_device_names_are_filtered(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ $this->page( [ 'visual_channel_off' => [ 'mobile', 'tablet' ] ] ) ],
            []
        );
        $this->assertSame( [ 'mobile' ], $rows[0]['visual_channel_off'] );
    }

    public function test_duplicates_are_deduped(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ $this->page( [ 'visual_channel_off' => [ 'mobile', 'mobile' ] ] ) ],
            []
        );
        $this->assertSame( [ 'mobile' ], $rows[0]['visual_channel_off'] );
    }

    public function test_non_string_entries_are_filtered_without_warning(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ $this->page( [ 'visual_channel_off' => [ [ 'x' ], 3, 'desktop' ] ] ) ],
            []
        );
        $this->assertSame( [ 'desktop' ], $rows[0]['visual_channel_off'] );
    }

    public function test_non_array_value_yields_empty(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ $this->page( [ 'visual_channel_off' => 'mobile' ] ) ],
            []
        );
        $this->assertSame( [], $rows[0]['visual_channel_off'] );
    }

    public function test_key_absent_legacy_row_yields_empty_no_notice(): void {
        // Legacy scan predates the Railway wire field entirely — the key is never set.
        $rows = AIAS_Scan_Status::build_pages(
            [ $this->page( [] ) ], // no 'visual_channel_off' key at all
            []
        );
        $this->assertSame( [], $rows[0]['visual_channel_off'] );
    }

    public function test_non_ok_status_class_forces_empty_even_with_valid_field(): void {
        $rows = AIAS_Scan_Status::build_pages(
            [ $this->page( [
                'broken_devices'      => [ [ 'device' => 'mobile', 'reason' => 'tier1_http_rate_limit' ] ], // -> 'partial'
                'visual_channel_off'  => [ 'mobile' ],
            ] ) ],
            []
        );
        $this->assertSame( 'partial', $rows[0]['status_class'] );
        $this->assertSame( [], $rows[0]['visual_channel_off'] );
    }
}

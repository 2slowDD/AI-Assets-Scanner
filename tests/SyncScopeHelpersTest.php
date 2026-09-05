<?php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * FU-AAS-SYNC-SCOPE-LAST-SCAN — the two public static helpers behind spec §3.1 / §3.3.
 * scanned_patterns(): deduped, order-preserving UrlPattern::from_url over every worker row.
 * rule_counts_from_rules(): TOTAL over its input (spec §3.3 wire site 3) — the invariant the
 * JS flag derivation rests on. A rule with an unknown group_id counts as aggressive, never skipped.
 */
class SyncScopeHelpersTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( (string) $u, $c ) );
    }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_scanned_patterns_dedupes_and_keeps_page_order(): void {
        $pages = [
            [ 'url' => 'https://site.test/b/' ],
            [ 'url' => 'https://site.test/' ],
            [ 'url' => 'https://site.test/b?x=1' ],   // collapses onto /b (query stripped, trailing slash stripped)
            [ 'url' => '' ],                            // skipped
            [ 'status' => 'error' ],                    // no url — skipped, never fatal
            'https://site.test/plain-string-row',       // final-review fold: the ROW itself is a bare string, not an array — skipped, no warning
            [ 'url' => [ 'x' ] ],                        // final-review fold: url is an array, not a string — skipped, no warning
            [ 'url' => 123 ],                            // final-review fold: url is an int, not a string — skipped, no warning
            [ 'url' => 'https://ext.test/p/' ],         // external pages ARE in the scope set (host filter is separate)
        ];
        $this->assertSame(
            [ 'https://site.test/b', 'https://site.test/', 'https://ext.test/p' ],
            ScannerAjax::scanned_patterns( $pages )
        );
    }

    public function test_rule_counts_from_rules_is_total(): void {
        $rules = [
            [ 'group_id' => 1 ], [ 'group_id' => 2 ], [ 'group_id' => 2 ],
            [ 'group_id' => 7 ],          // unknown group => aggressive (sync()'s else-branch), never dropped
            [ 'asset_handle' => 'x' ],    // missing group_id => aggressive, never dropped
        ];
        $c = ScannerAjax::rule_counts_from_rules( $rules );
        $this->assertSame( [ 'safe' => 1, 'aggressive' => 4 ], $c );
        $this->assertSame( count( $rules ), $c['safe'] + $c['aggressive'], 'totality: every rule lands in exactly one counter' );
        $this->assertSame( [ 'safe' => 0, 'aggressive' => 0 ], ScannerAjax::rule_counts_from_rules( [] ) );
    }
}

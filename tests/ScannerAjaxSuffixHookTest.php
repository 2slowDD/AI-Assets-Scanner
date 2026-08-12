<?php
// tests/ScannerAjaxSuffixHookTest.php
namespace CUScanner\Tests;

use CUScanner\Admin\ScannerAjax;
use CUScanner\Scanner\PluginDetector;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * AC-10 — `cu_scanner_suffix_suggested_unresolved`, the probe-side half of the spec §6
 * telemetry. The probe response hands the browser a bypass suffix for EVERY URL on a
 * host, but it only ever resolves ONE of them ($url1). Every other URL therefore leaves
 * the probe with a suffix that will be appended to an unresolved URL — the exact pairing
 * a query-blind 301 at the origin answers with a hard 404. This hook makes that pairing
 * countable at the moment it is created.
 *
 * Probe-side, never submit-side: suffix suggestions and resolution provenance are both in
 * scope only here, which is why the event is named *suggested*, not *applied*.
 *
 * ⚠️ P17 — every case drives the REAL ScannerAjax::probe_target_stack() end to end, from
 * $_POST through the real PluginDetector to the real do_action() call, and asserts on the
 * payload that reached WP_Mock's action machinery. No injected $resolved_per_url map and
 * no source-text pin: a test that handed the handler a pre-built map would prove the
 * loop's arithmetic and nothing about whether the hook is wired into the shipped path.
 *
 * Capture note: WP_Mock DEFINES do_action() itself, so WP_Mock::userFunction('do_action')
 * registers an expectation that never runs — the real WP_Mock implementation is what
 * executes, routing to WP_Mock::onAction(). Payload assertions therefore go through
 * expectAction()/onAction()->with(), which SubmitJobPayloadTest already uses for this
 * hook's sibling, cu_scanner_target_bypass_missing.
 *
 * What that mechanism does and does not check: it matches on a string projection of the
 * payload (WP_Mock\Hook::safe_offset), concatenating key.value in ITERATION ORDER — so key
 * names, key order and the set of keys are all genuinely pinned. Values are not compared
 * by type, though: scalars collapse through `(string) $value`, so 0, '0' and false-ish
 * near-misses are indistinguishable here. The `(string) $host` cast in the handler is
 * therefore exactly the kind of defect these tests CANNOT see — it stands on the reading
 * of $by_host's keys, not on a covering assertion.
 */
class ScannerAjaxSuffixHookTest extends TestCase {

    private const HOOK = 'cu_scanner_suffix_suggested_unresolved';

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        unset( $_POST['urls'] );
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * A warm host detection entry, keyed the way an earlier scan of $submitted left it.
     * Feeding the detector a warm entry whose submitted_url IS the URL the handler probes
     * keeps the whole run at zero HTTP while still executing the real detector: the
     * same-path cache hit returns before any wp_remote_get.
     */
    private function host_entry( string $submitted, array $suffixes ): array {
        return [
            'outcome'             => 'class_a_clean',
            'detected'            => [ 'litespeed' => [ 'name' => 'LiteSpeed Cache', 'class' => 'A' ] ],
            'security_stacks'     => [],
            'is_wordpress'        => true,
            'page_cache_detected' => true,
            'bypass_suffixes'     => $suffixes,
            'submitted_url'       => $submitted,
            'resolved_url'        => $submitted,
            'resolution_source'   => 'none',
            'redirect_final'      => $submitted,
        ];
    }

    /** The payload shape the spec fixes: url, host, suffixes — in that order. */
    private function payload( string $url, string $host, array $suffixes ): array {
        return [ 'url' => $url, 'host' => $host, 'suffixes' => $suffixes ];
    }

    /**
     * Watch for ONE exact payload and record it in $seen if the handler ever emits it.
     * A responder only reacts to an argument-for-argument match, so a watcher that stays
     * silent is proof that this precise event did not go out.
     */
    private function watch( array $payload, array &$seen ): void {
        WP_Mock::onAction( self::HOOK )->with( $payload )->perform(
            function () use ( $payload, &$seen ) {
                $seen[] = $payload;
            }
        );
    }

    /** The host detection key, via the SAME builder production uses — never a literal. */
    private function host_key( string $host ): string {
        return PluginDetector::__test_build_cache_key( 'https', $host, '443' );
    }

    /**
     * Drive the real AJAX handler over $urls. $entries maps a host detection transient
     * key to the cached entry the detector should find there.
     *
     * @param array<string,array> $entries host cache key => cached host entry
     */
    private function fire_probe( array $urls, array $entries ): void {
        WP_Mock::userFunction( 'check_ajax_referer' )->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
        WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
        WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( fn( $u ) => $u );
        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
            fn( $url, $component = -1 ) => parse_url( $url, $component )
        );
        WP_Mock::userFunction( 'get_transient' )->andReturnUsing( fn( $key ) => $entries[ $key ] ?? false );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );

        // The handler ends in wp_send_json(); throwing is this suite's house idiom for
        // short-circuiting it (cf. ProbeTargetStackEndpointTest), and it also proves the
        // hook fires BEFORE the response is built rather than as a side effect of it.
        WP_Mock::userFunction( 'wp_send_json' )->andReturnUsing( function () {
            throw new \Exception( 'wp_send_json_called' );
        } );

        $_POST['urls'] = $urls;
        try {
            ( new ScannerAjax() )->probe_target_stack();
            $this->fail( 'the handler must reach wp_send_json' );
        } catch ( \Exception $e ) {
            $this->assertSame( 'wp_send_json_called', $e->getMessage(),
                'the handler must reach its response, not die somewhere in the loop' );
        }
    }

    // -------------------------------------------------------------------------
    // AC-10 positive — a sibling URL on a suffix-bearing host fires the event, and
    // the URL the probe actually resolved does not ride along with it.
    // -------------------------------------------------------------------------

    public function test_fires_for_sibling_url_on_suffix_bearing_host(): void {
        $suffixes = [ 'LSCWP_CTRL=before_optm' ];

        // The contract: exact hook name, exact payload, exact key order.
        WP_Mock::expectAction( self::HOOK, $this->payload( 'https://host.com/pricing/', 'host.com', $suffixes ) );

        $probed = [];
        $this->watch( $this->payload( 'https://host.com/', 'host.com', $suffixes ), $probed );

        $this->fire_probe(
            [ 'https://host.com/', 'https://host.com/pricing/' ],
            [ $this->host_key( 'host.com' ) => $this->host_entry( 'https://host.com/', $suffixes ) ]
        );

        $this->assertConditionsMet();
        $this->assertSame( [], $probed,
            'the probed URL was resolved first-hand — emitting it too would drown the real signal' );
    }

    // -------------------------------------------------------------------------
    // AC-10 negative (a) — the URL the probe resolved never fires, even when it is
    // the only URL on the host. Its resolution is first-hand, so pairing a suffix
    // with it is not a guess.
    // -------------------------------------------------------------------------

    public function test_does_not_fire_for_the_probed_url_itself(): void {
        $suffixes = [ 'LSCWP_CTRL=before_optm' ];

        // Drop the `$u !== $probe_submitted` term and THIS is the payload that goes out.
        $seen = [];
        $this->watch( $this->payload( 'https://host.com/', 'host.com', $suffixes ), $seen );

        $this->fire_probe(
            [ 'https://host.com/' ],
            [ $this->host_key( 'host.com' ) => $this->host_entry( 'https://host.com/', $suffixes ) ]
        );

        $this->assertSame( [], $seen,
            'a URL the probe resolved itself carries no unresolved-suffix risk to report' );
    }

    // -------------------------------------------------------------------------
    // AC-10 negative (c) — no suffix, no event. With nothing appended the dispatched
    // URL is bare, the origin's own 301 applies and the worker follows it, so there
    // is no unresolved-URL-plus-suffix pairing in existence to report.
    // -------------------------------------------------------------------------

    public function test_does_not_fire_when_no_suffixes_were_suggested(): void {
        // Drop the `! empty( $suffixes )` term and THIS is the payload that goes out.
        $seen = [];
        $this->watch( $this->payload( 'https://host.com/pricing/', 'host.com', [] ), $seen );

        $this->fire_probe(
            [ 'https://host.com/', 'https://host.com/pricing/' ],
            [ $this->host_key( 'host.com' ) => $this->host_entry( 'https://host.com/', [] ) ]
        );

        $this->assertSame( [], $seen,
            'an empty suffix list means nothing is appended, so nothing is at risk' );
    }

    // -------------------------------------------------------------------------
    // AC-10 — the burst IS the design (spec §6): a many-URL scan on a warm
    // suffix-bearing host emits one event per sibling on its first sighting. No
    // throttling, no dedup, no aggregation. Two hosts in one request, so a payload
    // that carried the wrong host's name or the wrong host's suffix list is visible.
    // -------------------------------------------------------------------------

    public function test_emits_one_event_per_sibling_with_per_host_fields(): void {
        $ls = [ 'LSCWP_CTRL=before_optm' ];
        $pm = [ 'perfmatters=off', 'nocache=1' ];

        $seen = [];
        // Every event that SHOULD go out…
        $this->watch( $this->payload( 'https://host.com/pricing/', 'host.com',   $ls ), $seen );
        $this->watch( $this->payload( 'https://host.com/blog/',    'host.com',   $ls ), $seen );
        $this->watch( $this->payload( 'https://other.test/docs/',  'other.test', $pm ), $seen );
        // …and the ones that must not: the two probed URLs, and any sibling wearing
        // the other host's suffix list.
        $this->watch( $this->payload( 'https://host.com/',         'host.com',   $ls ), $seen );
        $this->watch( $this->payload( 'https://other.test/',       'other.test', $pm ), $seen );
        $this->watch( $this->payload( 'https://host.com/pricing/', 'host.com',   $pm ), $seen );
        $this->watch( $this->payload( 'https://other.test/docs/',  'other.test', $ls ), $seen );

        $this->fire_probe(
            [
                'https://host.com/',
                'https://host.com/pricing/',
                'https://host.com/blog/',
                'https://other.test/',
                'https://other.test/docs/',
            ],
            [
                $this->host_key( 'host.com' )   => $this->host_entry( 'https://host.com/',   $ls ),
                $this->host_key( 'other.test' ) => $this->host_entry( 'https://other.test/', $pm ),
            ]
        );

        $this->assertSame(
            [
                $this->payload( 'https://host.com/pricing/', 'host.com',   $ls ),
                $this->payload( 'https://host.com/blog/',    'host.com',   $ls ),
                $this->payload( 'https://other.test/docs/',  'other.test', $pm ),
            ],
            $seen,
            'one event per sibling, in scan order, each carrying ITS host and ITS host suffix list'
        );
    }
}

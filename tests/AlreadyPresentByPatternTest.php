<?php
namespace CUScanner\Tests;

use PHPUnit\Framework\TestCase;
use CUScanner\Scanner\RulePusher;

/**
 * Test double standing in for CodeUnloader\Core\RuleRepository.
 *
 * find_duplicate() implements the SAME semantics as the real 6-tuple SQL
 * (RuleRepository.php:62-95) so AC-12 can assert the in-memory set and the
 * predicate agree. Seeded rows are plain objects, matching get_all_rules().
 */
class FakeRuleRepo {
    public static array $rules  = [];
    public static array $groups = [];
    public static int   $create_group_calls = 0;

    public static function get_all_rules(): array  { return self::$rules; }
    public static function get_all_groups(): array { return self::$groups; }

    public static function create_group( string $name, string $description ) {
        self::$create_group_calls++;
        return 999;
    }

    public static function find_duplicate( array $data, int $exclude_id = 0 ): ?object {
        $device = $data['device_type'] ?? 'all';
        $group  = ( isset( $data['group_id'] ) && '' !== $data['group_id'] && null !== $data['group_id'] )
            ? (int) $data['group_id'] : 0;
        foreach ( self::$rules as $r ) {
            if ( $r->url_pattern === $data['url_pattern']
              && $r->match_type   === $data['match_type']
              && $r->asset_handle === $data['asset_handle']
              && $r->asset_type   === $data['asset_type']
              && ( $r->device_type ?? 'all' ) === $device
              && (int) ( $r->group_id ?? 0 ) === $group ) {
                return $r;
            }
        }
        return null;
    }

    public static function reset(): void {
        self::$rules = []; self::$groups = []; self::$create_group_calls = 0;
    }
}

class AlreadyPresentByPatternTest extends TestCase {

    public function setUp(): void {
        \WP_Mock::setUp();
        FakeRuleRepo::reset();
    }

    public function tearDown(): void { \WP_Mock::tearDown(); }

    /**
     * ⚠️ Registered per-test, NOT in setUp(): a second WP_Mock::userFunction() for the
     * same name does not override the first, so a setUp-registered `true` would make the
     * CU-inactive test unable to express its own precondition.
     */
    private function cu_plugin_active( bool $active ): void {
        \WP_Mock::userFunction( 'is_plugin_active', [ 'return' => $active ] );
    }

    private function rule( string $pattern, string $handle, string $type, string $device, int $group ): array {
        return [
            'url_pattern'  => $pattern,
            'match_type'   => 'exact',
            'asset_handle' => $handle,
            'asset_type'   => $type,
            'device_type'  => $device,
            'group_id'     => $group,
        ];
    }

    private function cu_json( array $rules ): array {
        return [
            'groups' => [
                [ 'id' => 1, 'name' => 'AA Scanner — Safe',       'description' => '' ],
                [ 'id' => 2, 'name' => 'AA Scanner — Aggressive', 'description' => '' ],
            ],
            'rules'  => $rules,
        ];
    }

    private function seed_groups(): void {
        FakeRuleRepo::$groups = [
            (object) [ 'id' => 11, 'name' => 'AA Scanner — Safe' ],
            (object) [ 'id' => 22, 'name' => 'AA Scanner — Aggressive' ],
        ];
    }

    private function seed_rule( string $pattern, string $handle, string $type, string $device, int $group_id ): void {
        FakeRuleRepo::$rules[] = (object) [
            'url_pattern'  => $pattern, 'match_type' => 'exact', 'asset_handle' => $handle,
            'asset_type'   => $type,    'device_type' => $device, 'group_id' => $group_id,
        ];
    }

    /** AC-11: CU unreachable => null ("cannot know"), NOT an empty array ("nothing present"). */
    public function test_returns_null_when_cu_plugin_inactive(): void {
        $this->cu_plugin_active( false );
        $pusher = new RulePusher( FakeRuleRepo::class );
        $this->assertNull( $pusher->already_present_by_pattern( $this->cu_json( [] ) ) );
    }

    /** AC-11 + R-2: a CU without the bulk API degrades to null instead of fataling. */
    public function test_returns_null_when_repo_lacks_get_all_rules(): void {
        $this->cu_plugin_active( true );
        $pusher = new RulePusher( \stdClass::class );
        $this->assertNull( $pusher->already_present_by_pattern( $this->cu_json( [] ) ) );
    }

    public function test_counts_matching_rules_per_pattern_and_group(): void {
        $this->seed_groups();
        $this->seed_rule( 'https://s.com/p', 'handle-a', 'css', 'all', 22 );

        $this->cu_plugin_active( true );
        $pusher = new RulePusher( FakeRuleRepo::class );
        $out = $pusher->already_present_by_pattern( $this->cu_json( [
            $this->rule( 'https://s.com/p', 'handle-a', 'style',  'all', 2 ), // already in CU
            $this->rule( 'https://s.com/p', 'handle-b', 'style',  'all', 2 ), // new
        ] ) );

        $this->assertSame( 1, $out['https://s.com/p']['aggressive'] );
        $this->assertSame( 0, $out['https://s.com/p']['safe'] );
    }

    /**
     * The transforms are load-bearing: CuJsonBuilder emits asset_type 'style', CU stores
     * 'css'. Keying off the raw rule instead of build_rule_payload() would match nothing.
     */
    public function test_applies_normalize_asset_type_before_matching(): void {
        $this->seed_groups();
        $this->seed_rule( 'https://s.com/p', 'h', 'js', 'all', 22 );

        $this->cu_plugin_active( true );
        $pusher = new RulePusher( FakeRuleRepo::class );
        $out = $pusher->already_present_by_pattern( $this->cu_json( [
            $this->rule( 'https://s.com/p', 'h', 'script', 'all', 2 ),
        ] ) );

        $this->assertSame( 1, $out['https://s.com/p']['aggressive'], "'script' must normalize to 'js'" );
    }

    /**
     * AC-12: absent device_type must normalize to 'all', matching find_duplicate.
     *
     * ⚠️ build_rule_payload() reads $rule['device_type'] with NO ?? default, so a rule
     * missing the key would emit an undefined-key warning on BOTH this path and sync()'s.
     * already_present_by_pattern() therefore normalizes the key before delegating —
     * result-build must never warn on a shape sync() merely tolerates.
     */
    public function test_absent_device_type_normalizes_to_all(): void {
        $this->seed_groups();
        $this->seed_rule( 'https://s.com/p', 'h', 'css', 'all', 22 );

        $rule = $this->rule( 'https://s.com/p', 'h', 'style', 'all', 2 );
        unset( $rule['device_type'] ); // the ratchet can re-inject a rule shaped by an older version

        $this->cu_plugin_active( true );
        $pusher = new RulePusher( FakeRuleRepo::class );
        $out = $pusher->already_present_by_pattern( $this->cu_json( [ $rule ] ) );

        $this->assertSame( 1, $out['https://s.com/p']['aggressive'] );
    }

    /**
     * AC-12, CU side: CU's device_type column is NULLABLE, which is exactly why
     * find_duplicate applies `device_type ?? 'all'`. A stored NULL must match a scan
     * rule whose device_type is 'all'.
     *
     * This is the case that makes tuple_key()'s normalization load-bearing. Without it
     * the row keys as '' and matches nothing, and the customer is told a rule they
     * already own is a new finding — the exact defect this whole change exists to fix.
     */
    public function test_cu_row_with_null_device_type_matches_all(): void {
        $this->seed_groups();
        FakeRuleRepo::$rules[] = (object) [
            'url_pattern'  => 'https://s.com/p', 'match_type' => 'exact', 'asset_handle' => 'h',
            'asset_type'   => 'css',             'device_type' => null,   'group_id'     => 22,
        ];

        $this->cu_plugin_active( true );
        $pusher = new RulePusher( FakeRuleRepo::class );
        $out = $pusher->already_present_by_pattern( $this->cu_json( [
            $this->rule( 'https://s.com/p', 'h', 'style', 'all', 2 ),
        ] ) );

        $this->assertSame( 1, $out['https://s.com/p']['aggressive'],
            "a NULL device_type in CU must normalize to 'all', as find_duplicate does" );
    }

    /** Rules in a group CU does not have are all new — matching what sync would report. */
    public function test_missing_group_yields_zero_already_present(): void {
        FakeRuleRepo::$groups = [ (object) [ 'id' => 11, 'name' => 'AA Scanner — Safe' ] ];
        $this->seed_rule( 'https://s.com/p', 'h', 'css', 'all', 22 );

        $this->cu_plugin_active( true );
        $pusher = new RulePusher( FakeRuleRepo::class );
        $out = $pusher->already_present_by_pattern( $this->cu_json( [
            $this->rule( 'https://s.com/p', 'h', 'style', 'all', 2 ),
        ] ) );

        $this->assertSame( 0, $out['https://s.com/p']['aggressive'] ?? 0 );
    }

    /** AC-9: result-build is READ-ONLY. Creating a group here would mutate CU on a scan. */
    public function test_never_creates_a_cu_group(): void {
        FakeRuleRepo::$groups = [];
        $this->cu_plugin_active( true );
        $pusher = new RulePusher( FakeRuleRepo::class );
        $pusher->already_present_by_pattern( $this->cu_json( [
            $this->rule( 'https://s.com/p', 'h', 'style', 'all', 2 ),
        ] ) );
        $this->assertSame( 0, FakeRuleRepo::$create_group_calls );
    }

    /** AC-12: the in-memory set and find_duplicate must agree, rule for rule. */
    public function test_in_memory_set_agrees_with_find_duplicate(): void {
        $this->seed_groups();
        $this->seed_rule( 'https://s.com/p', 'h1', 'css', 'all',     22 );
        $this->seed_rule( 'https://s.com/p', 'h2', 'js',  'desktop', 22 );

        $rules = [
            $this->rule( 'https://s.com/p', 'h1', 'style',  'all',     2 ),
            $this->rule( 'https://s.com/p', 'h2', 'script', 'desktop', 2 ),
            $this->rule( 'https://s.com/p', 'h3', 'style',  'all',     2 ),
        ];

        $this->cu_plugin_active( true );
        $pusher = new RulePusher( FakeRuleRepo::class );
        $out = $pusher->already_present_by_pattern( $this->cu_json( $rules ) );

        // Independently count via the predicate itself.
        $expected = 0;
        foreach ( $rules as $r ) {
            $payload = [
                'url_pattern'  => $r['url_pattern'],
                'match_type'   => 'exact',
                'asset_handle' => $r['asset_handle'],
                'asset_type'   => $r['asset_type'] === 'style' ? 'css' : 'js',
                'device_type'  => $r['device_type'],
                'group_id'     => 22,
            ];
            if ( FakeRuleRepo::find_duplicate( $payload ) !== null ) { $expected++; }
        }

        $this->assertSame( $expected, $out['https://s.com/p']['aggressive'] );
        $this->assertSame( 2, $expected, 'sanity: the fixture should have exactly 2 matches' );
    }
}

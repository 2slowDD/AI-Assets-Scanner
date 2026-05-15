<?php
namespace CUScanner\Tests;

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Spec §3.5 multi-optimizer composition — verifies the scan-trigger flow
 * when both Class A (suffix-only) and Class C (orchestrator-disabled) plugins
 * are active. Worked example: WP Rocket + FlyingPress.
 */
class MultiOptimizerCompositionTest extends TestCase {

    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_flying_press_contributes_no_optimize_suffix_post_reclass(): void {
        // Arrange: WP Rocket (Class A) + FlyingPress (Class A post-reclass) both active.
        // FlyingPress is now class A; it should contribute 'no_optimize' to bypass_suffixes.
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => in_array( $f, [
                'wp-rocket/wp-rocket.php',
                'flying-press/flying-press.php',
            ], true ) );

        $detector = new \CUScanner\Scanner\PluginDetector();
        $entries  = $detector->detect_typed();

        // FlyingPress is class A post-reclass — no class C entries from these two plugins.
        $class_c = array_filter( $entries, fn( $e ) => ( $e['class'] ?? '' ) === 'C' );
        $this->assertCount( 0, $class_c, 'FlyingPress reclassed C -> A; no class C entries expected' );
        $this->assertSame( 'A', $entries['flying-press/flying-press.php']['class'] );

        $bypass_suffixes = \CUScanner\Scanner\PluginDetector::build_bypass_suffixes( $entries );
        sort( $bypass_suffixes );
        $this->assertSame( [ 'no_optimize', 'nowprocket' ], $bypass_suffixes,
            'WP Rocket + FlyingPress both class A; both contribute bypass suffixes' );
    }

    public function test_orchestrator_empty_when_only_class_a_plugins_active(): void {
        // WP Rocket + FlyingPress both class A — orchestrator should have empty strategies array.
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => in_array( $f, [
                'wp-rocket/wp-rocket.php',
                'flying-press/flying-press.php',
            ], true ) );

        $orchestrator = \CUScanner\Scanner\OptimizerBypassOrchestrator::build_default_orchestrator();

        $rp = new \ReflectionClass( $orchestrator );
        $prop = $rp->getProperty( 'strategies' );
        $prop->setAccessible( true );
        $strategies = $prop->getValue( $orchestrator );

        $this->assertIsArray( $strategies );
        $this->assertEmpty( $strategies,
            'Both WP Rocket and FlyingPress are class A post-reclass; orchestrator skips both' );
    }

    public function test_required_event_types_have_emit_call_sites(): void {
        // Spec §3.5 event contract: all four event types must have emit() call sites
        // in the production source tree (submit_job + orchestrator).
        // NOTE: In class-scanner-ajax.php the event name appears on the line after
        // EventEmitter::emit( so we search for the quoted event name anywhere in
        // proximity to an emit call — a per-name grep is the correct approach.
        $expected_events = [
            'scan_request_received',
            'optimizer_detected',
            'optimizer_disabled',
            'optimizer_restored',
        ];
        $sources = [
            CU_SCANNER_DIR . 'admin/class-scanner-ajax.php',
            CU_SCANNER_DIR . 'includes/scanner/class-optimizer-bypass-orchestrator.php',
        ];
        $haystacks = '';
        foreach ( $sources as $path ) {
            if ( file_exists( $path ) ) {
                $haystacks .= file_get_contents( $path );
            }
        }
        $this->assertStringContainsString(
            'EventEmitter::emit(',
            $haystacks,
            'no EventEmitter::emit call found in source files'
        );
        foreach ( $expected_events as $name ) {
            // Event name appears either on the same line as emit( or on the next line.
            $this->assertMatchesRegularExpression(
                '/EventEmitter::emit\s*\([^;]*\'' . preg_quote( $name, '/' ) . '\'/s',
                $haystacks,
                "no emit call site found for {$name}"
            );
        }
    }
}

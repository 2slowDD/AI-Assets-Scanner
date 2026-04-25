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

    public function test_class_c_consent_required_when_flying_press_active(): void {
        // Arrange: WP Rocket (Class A) + FlyingPress (Class C) both active.
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => in_array( $f, [
                'wp-rocket/wp-rocket.php',
                'flying-press/flying-press.php',
            ], true ) );

        $detector = new \CUScanner\Scanner\PluginDetector();
        $entries  = $detector->detect_typed();

        $class_c = array_filter( $entries, fn( $e ) => ( $e['class'] ?? '' ) === 'C' );
        $this->assertCount( 1, $class_c );
        $this->assertSame( 'FlyingPress', $entries['flying-press/flying-press.php']['name'] );

        $bypass_suffixes = \CUScanner\Scanner\PluginDetector::build_bypass_suffixes( $entries );
        $this->assertSame( [ 'nowprocket' ], $bypass_suffixes,
            'Class A suffix builds; Class C contributes nothing to suffix list' );
    }

    public function test_strategy_factory_resolves_flying_press(): void {
        $strategy = \CUScanner\Scanner\StrategyFactory::for_method( 'flying_press' );
        $this->assertInstanceOf(
            \CUScanner\Scanner\Strategies\FlyingPressBypass::class,
            $strategy
        );
    }

    public function test_orchestrator_runs_only_class_c_strategies(): void {
        // Build orchestrator from a mixed A+C detector list.
        // build_default_orchestrator filters to Class C — Class A entries are NOT
        // wrapped in any strategy.
        WP_Mock::userFunction( 'is_plugin_active' )
            ->andReturnUsing( fn( $f ) => in_array( $f, [
                'wp-rocket/wp-rocket.php',
                'flying-press/flying-press.php',
            ], true ) );

        $orchestrator = \CUScanner\Scanner\OptimizerBypassOrchestrator::build_default_orchestrator();

        // Reflect to verify the strategies array contains only FlyingPress.
        $rp = new \ReflectionClass( $orchestrator );
        $prop = $rp->getProperty( 'strategies' );
        $prop->setAccessible( true );
        $strategies = $prop->getValue( $orchestrator );

        $this->assertCount( 1, $strategies );
        $this->assertSame( 'flying_press', $strategies[0]->slug() );
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

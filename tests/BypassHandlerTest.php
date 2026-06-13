<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\BypassHandler;
use CUScanner\Scanner\EventEmitter;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class BypassHandlerTest extends TestCase {
	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$_GET = [];
		EventEmitter::set_client_for_testing( null );
		BypassHandler::for_testing_set_token_validator( null );
		BypassHandler::for_testing_set_active_plugins( null );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		$_GET = [];
		BypassHandler::for_testing_set_token_validator( null );
		BypassHandler::for_testing_set_active_plugins( null );
		parent::tearDown();
	}

	public function test_handle_with_no_token_does_nothing(): void {
		// No $_GET['cu_scan_token'] — handler returns early, no events emitted.
		WP_Mock::userFunction( 'get_option' )
			->with( 'aias_pending_events', [] )
			->andReturn( [] );
		BypassHandler::handle_wp_loaded();
		// No events emitted (would require update_option to be called)
		$this->assertConditionsMet();
	}

	public function test_invalid_token_logs_misuse_event(): void {
		$_GET['cu_scan_token'] = 'tok-bad';
		$_SERVER['REMOTE_ADDR']     = '1.2.3.4';
		$_SERVER['HTTP_USER_AGENT'] = 'TestUA/1.0';
		$_SERVER['REQUEST_URI']     = '/some/path';

		BypassHandler::for_testing_set_token_validator( fn( $t ) => false );

		// EventEmitter::emit will read+update aias_pending_events
		WP_Mock::userFunction( 'get_option' )
			->with( 'aias_pending_events', [] )
			->andReturn( [] );
		$captured = null;
		WP_Mock::userFunction( 'update_option' )
			->andReturnUsing( function ( $key, $value ) use ( &$captured ) {
				$captured = $value;
				return true;
			} );
		WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );
		WP_Mock::userFunction( 'wp_schedule_single_event' );

		// sanitize_text_field and wp_unslash are called on $_GET, $_SERVER values
		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturnUsing( fn( $v ) => $v );
		WP_Mock::userFunction( 'wp_unslash' )
			->andReturnUsing( fn( $v ) => $v );

		BypassHandler::handle_wp_loaded();

		$this->assertNotNull( $captured );
		$names = array_map( fn( $e ) => $e['name'], $captured );
		$this->assertContains( 'bypass_token_misuse', $names );

		// Verify all three fields are hashed (never raw)
		$event = array_values( array_filter( $captured, fn( $e ) =>
			$e['name'] === 'bypass_token_misuse' ) )[0];
		$this->assertSame( substr( hash( 'sha256', '1.2.3.4' ), 0, 16 ),
			$event['fields']['remote_addr'] );
		$this->assertSame( substr( hash( 'sha256', 'TestUA/1.0' ), 0, 16 ),
			$event['fields']['user_agent'] );
		$this->assertSame( substr( hash( 'sha256', '/some/path' ), 0, 16 ),
			$event['fields']['path'] );
	}

	public function test_valid_token_no_misuse_event(): void {
		$_GET['cu_scan_token'] = 'tok-good';
		$_SERVER['REMOTE_ADDR'] = '1.2.3.4';
		BypassHandler::for_testing_set_token_validator( fn( $t ) => $t === 'tok-good' );
		BypassHandler::for_testing_set_active_plugins( [] );  // no optimizers

		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturnUsing( fn( $v ) => $v );
		WP_Mock::userFunction( 'wp_unslash' )
			->andReturnUsing( fn( $v ) => $v );
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );

		WP_Mock::userFunction( 'get_option' )->andReturn( [] );
		WP_Mock::userFunction( 'update_option' )->never();
		WP_Mock::userFunction( 'wp_next_scheduled' )->never();
		WP_Mock::userFunction( 'wp_schedule_single_event' )->never();

		BypassHandler::handle_wp_loaded();
		$this->assertConditionsMet();
	}

	public function test_class_a_star_excluded_from_hook_removal(): void {
		// LiteSpeed (A_star) is detected — but BypassHandler must NOT touch its hooks.
		$_GET['cu_scan_token'] = 'tok-good';
		BypassHandler::for_testing_set_token_validator( fn( $t ) => true );
		BypassHandler::for_testing_set_active_plugins( [
			'litespeed-cache/litespeed-cache.php' => true,
		] );

		global $wp_filter;
		$wp_filter = [];  // empty — handler should not crash on missing hooks

		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturnUsing( fn( $v ) => $v );
		WP_Mock::userFunction( 'wp_unslash' )
			->andReturnUsing( fn( $v ) => $v );

		// PluginDetector::detect_typed uses is_plugin_active; LiteSpeed is A_star not A
		WP_Mock::userFunction( 'is_plugin_active' )
			->andReturnUsing( function( $plugin ) {
				return $plugin === 'litespeed-cache/litespeed-cache.php';
			} );

		// No exception, no action; assert no update_option fired (no event emission needed).
		WP_Mock::userFunction( 'get_option' )->andReturn( [] );
		BypassHandler::handle_wp_loaded();
		$this->assertConditionsMet();
	}

	public function test_does_not_throw_when_target_hook_missing(): void {
		// WP Rocket detected but its hook isn't registered (testing edge case).
		$_GET['cu_scan_token'] = 'tok-good';
		BypassHandler::for_testing_set_token_validator( fn( $t ) => true );
		BypassHandler::for_testing_set_active_plugins( [
			'wp-rocket/wp-rocket.php' => true,
		] );

		global $wp_filter;
		$wp_filter = [];  // hooks not registered; handler must be defensive

		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturnUsing( fn( $v ) => $v );
		WP_Mock::userFunction( 'wp_unslash' )
			->andReturnUsing( fn( $v ) => $v );

		WP_Mock::userFunction( 'is_plugin_active' )
			->andReturnUsing( function( $plugin ) {
				return $plugin === 'wp-rocket/wp-rocket.php';
			} );

		WP_Mock::userFunction( 'get_option' )->andReturn( [] );
		BypassHandler::handle_wp_loaded();
		$this->assertConditionsMet();  // no crash
	}

	// FU-AAS-BYPASS-HOOK-RESORT (2026-06-13): sweeping removal must go through core
	// remove_all_filters() (which calls WP_Hook::resort_active_iterations()) — NOT a
	// direct unset of $wp_filter[$tag]->callbacks[$priority], which corrupts mid-apply
	// iteration state and emits "Undefined array key <priority>" + "foreach() null" warnings.
	public function test_class_a_sweeping_removal_uses_remove_all_filters(): void {
		$_GET['cu_scan_token'] = 'tok-good';
		BypassHandler::for_testing_set_token_validator( fn( $t ) => true );
		BypassHandler::for_testing_set_active_plugins( [
			'wp-rocket/wp-rocket.php' => true,  // Class A — single hook: template_redirect@999
		] );

		global $wp_filter;
		$wp_filter = [ 'template_redirect' => new \stdClass() ];  // present → isset() guard passes

		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => $v );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
		WP_Mock::userFunction( 'is_plugin_active' )
			->andReturnUsing( fn( $plugin ) => $plugin === 'wp-rocket/wp-rocket.php' );
		WP_Mock::userFunction( 'get_option' )->andReturn( [] );

		// The fix: removal must call core remove_all_filters($tag, $priority), once.
		WP_Mock::userFunction( 'remove_all_filters' )
			->once()
			->with( 'template_redirect', 999 );

		BypassHandler::handle_wp_loaded();
		$this->assertConditionsMet();
	}
}

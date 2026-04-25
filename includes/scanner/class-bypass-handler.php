<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Class A defense-in-depth bypass handler.
 *
 * Runs on `wp_loaded` priority 0 alongside BypassManager. Where BypassManager
 * handles scan-access concerns (token validation, password-protected pages),
 * this handler removes Class A optimizer hooks so the cold pass measures
 * un-mutated CSS/JS delivery.
 *
 * Class A* (LiteSpeed, FlyingPress) hook on muplugins_loaded — earlier than
 * any wp_loaded handler can run. Their bypass relies solely on the
 * suffix-injection path (Task 2.2 + 2.9). v1 ships A* with single-layer
 * confidence per spec §3 footnote.
 */
class BypassHandler {
	/**
	 * Per-plugin hook removal map. Each entry is an array of:
	 *   [ 'tag' => string, 'callback' => callable|null, 'priority' => int ]
	 *
	 * callback === null means "remove ALL callbacks at this priority/tag".
	 * Entries with empty arrays rely on suffix-only bypass (no PHP hook removal needed).
	 */
	private const HOOK_REMOVAL_MAP = [
		'wp-rocket/wp-rocket.php'           => [
			// WP Rocket's main HTML rewrite hook
			[ 'tag' => 'template_redirect', 'callback' => null, 'priority' => 999 ],
		],
		'perfmatters/perfmatters.php'       => [
			// Perfmatters' delay/defer hook chain
			[ 'tag' => 'wp_print_styles',  'callback' => null, 'priority' => 999 ],
			[ 'tag' => 'wp_print_scripts', 'callback' => null, 'priority' => 999 ],
		],
		'autoptimize/autoptimize.php'       => [
			// Autoptimize honors ?ao_noptimize=1 internally; defense-in-depth optional.
			[ 'tag' => 'wp_loaded', 'callback' => null, 'priority' => 2 ],
		],
		'nitropack/main.php'                => [
			// NitroPack: edge cache mostly; PHP-side hook removal limited.
			// Spec notes ?nonitro is the primary mechanism.
		],
		'asset-cleanup/asset-cleanup.php'  => [
			// Asset CleanUp honors ?wpacu_no_load itself; defense-in-depth optional.
		],
	];

	/** @var callable|null Injected token validator for testing. */
	private static $token_validator = null;

	/** @var array<string, mixed>|null Injected active plugins map for testing. */
	private static ?array $active_plugins = null;

	// -------------------------------------------------------------------------
	// Test seams
	// -------------------------------------------------------------------------

	public static function for_testing_set_token_validator( ?callable $fn ): void {
		self::$token_validator = $fn;
	}

	public static function for_testing_set_active_plugins( ?array $map ): void {
		self::$active_plugins = $map;
	}

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init(): void {
		add_action( 'wp_loaded', [ self::class, 'handle_wp_loaded' ], 0 );
	}

	// -------------------------------------------------------------------------
	// Handler
	// -------------------------------------------------------------------------

	public static function handle_wp_loaded(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token-based bypass; nonce architecturally N/A.
		$raw_token = isset( $_GET['cu_scan_token'] )
			? sanitize_text_field( wp_unslash( $_GET['cu_scan_token'] ) )
			: '';

		if ( $raw_token === '' ) {
			return;
		}

		if ( ! self::is_valid_token( $raw_token ) ) {
			self::log_misuse();
			return;
		}

		$detector_entries = self::get_detector_entries();
		$bypassed         = [];

		foreach ( $detector_entries as $file => $entry ) {
			// Class A* hooks on muplugins_loaded — too early for wp_loaded removal.
			// Only process Class A here.
			if ( ( $entry['class'] ?? '' ) !== 'A' ) {
				continue;
			}

			foreach ( self::HOOK_REMOVAL_MAP[ $file ] ?? [] as $hook ) {
				self::remove_hook( $hook['tag'], $hook['callback'], $hook['priority'] );
			}

			$bypassed[] = $entry['name'] ?? $file;
		}

		// Footer comment only in debug builds (production: no info disclosure).
		if ( ! empty( $bypassed ) && self::is_debug_build() ) {
			add_action( 'wp_footer', static function () use ( $bypassed ) {
				printf(
					'<!-- CU Scanner: bypass active for: %s -->',
					esc_html( implode( ', ', $bypassed ) )
				);
			} );
		}
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	private static function is_valid_token( string $token ): bool {
		if ( self::$token_validator !== null ) {
			return (bool) call_user_func( self::$token_validator, $token );
		}
		return ( new BypassManager() )->is_valid_token( $token );
	}

	/**
	 * Return entries from PluginDetector::detect_typed().
	 * When $active_plugins is injected (tests), PluginDetector will use the
	 * WP_Mock stubs for is_plugin_active, so detect_typed() is called directly.
	 *
	 * @return array<string, array>
	 */
	private static function get_detector_entries(): array {
		return ( new PluginDetector() )->detect_typed();
	}

	/**
	 * Remove all callbacks at a given priority for a hook tag.
	 * callback === null means sweeping removal of the entire priority bucket.
	 */
	private static function remove_hook( string $tag, ?callable $callback, int $priority ): void {
		if ( $callback !== null ) {
			remove_filter( $tag, $callback, $priority );
			remove_action( $tag, $callback, $priority );
			return;
		}

		// Sweeping removal: drop the entire priority bucket for this tag.
		global $wp_filter;

		if ( ! isset( $wp_filter[ $tag ] ) ) {
			return;
		}

		$hook_obj = $wp_filter[ $tag ];

		if ( is_object( $hook_obj ) && isset( $hook_obj->callbacks ) && is_array( $hook_obj->callbacks ) ) {
			unset( $hook_obj->callbacks[ $priority ] );
		} elseif ( is_array( $hook_obj ) ) {
			unset( $wp_filter[ $tag ][ $priority ] );
		}
	}

	/**
	 * Emit a security event for token misuse. Fields are hashed (never raw).
	 */
	private static function log_misuse(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- guarded by isset before sanitize.
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- guarded by isset before sanitize.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- guarded by isset before sanitize.
		$path = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		EventEmitter::emit(
			'bypass_token_misuse',
			'security',
			[
				'remote_addr' => substr( hash( 'sha256', $remote ), 0, 16 ),
				'user_agent'  => substr( hash( 'sha256', $ua ),     0, 16 ),
				'path'        => substr( hash( 'sha256', $path ),   0, 16 ),
			],
			''
		);
	}

	private static function is_debug_build(): bool {
		return defined( 'WP_DEBUG' ) && WP_DEBUG
			&& defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	}
}

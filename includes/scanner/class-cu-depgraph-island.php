<?php
namespace CUScanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Per-page declared-dependency island harvester (FU-FDEG-DEPGRAPH Phase B, Plan B Task 2).
 *
 * Serialises `wp_scripts()->registered` into a single JSON island so the scanner
 * worker can read WordPress' *declared* dependency graph instead of inferring it
 * from emitted markup.
 *
 * Island contract — SHARED with the worker parser, do NOT diverge:
 *
 *   <script type="application/json" id="cu-dep-graph">{"v":1,"dropped":N,"truncated":0,
 *     "scripts":{"<handle>":{"d":["dep",...],"a":0}}}</script>
 *
 * The worker's matcher tolerates single-quoted/unquoted `id` purely to survive
 * third-party HTML minifiers. That leniency does NOT bless alternate emission:
 * the producer contract is the double-quoted form above, and exactly one island
 * per page.
 *
 * F-SEC: the island is emitted ONLY on a request that carries a validated
 * `cu_scan_token` AND the `cu_dep_graph` marker. It never renders on a public
 * view. Both conditions are re-checked at emit time (the `$armed` latch), so a
 * stray `wp_footer` callback cannot leak the registry.
 */
final class CU_DepGraph_Island {
	/**
	 * Master switch for island emission.
	 * KEEP IN SYNC with the worker's DEP_GRAPH_GUARD_ENABLED kill switch —
	 * a worker that is parsing islands must not be starved of them, and a
	 * worker with the guard off should not be paying for their bytes.
	 */
	private const ISLAND_ENABLED = true;

	/** Query arg that opts a validated-token request into island emission. */
	private const MARKER = 'cu_dep_graph';

	/** Hard cap on the encoded island, in bytes (128 KB). */
	private const CAP_BYTES = 131072;

	/** Charset allowlist for handles and deps — shared contract with the worker. */
	private const HANDLE_RE = '/^[A-Za-z0-9_.:\-]{1,128}$/';

	/**
	 * JSON_HEX_* escape `< > & ' "` to \uXXXX inside string values, which is what
	 * makes the raw echo safe: no registry value can close the <script> element.
	 */
	private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	/** @var bool True once a validated token + marker request armed the footer hook. */
	private static bool $armed = false;

	/** @var bool True once the island was echoed — enforces exactly one per page. */
	private static bool $emitted = false;

	// -------------------------------------------------------------------------
	// Test seam
	// -------------------------------------------------------------------------

	public static function for_testing_reset(): void {
		self::$armed   = false;
		self::$emitted = false;
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Arm the island for this request. Called by BypassHandler at the point the
	 * scan token has been confirmed valid.
	 *
	 * @param string $token The already-validated scan token ('' when absent/invalid).
	 */
	public static function maybe_register( string $token ): void {
		if ( ! self::ISLAND_ENABLED ) {
			return;
		}

		// Exactly one island per page: a second arming attempt is a no-op.
		if ( self::$armed ) {
			return;
		}

		// Caller validated the token; an empty string means there is none.
		if ( $token === '' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token-gated headless request, nonce N/A.
		if ( ! isset( $_GET[ self::MARKER ] ) ) {
			return;
		}

		self::$armed = true;

		nocache_headers();
		add_action( 'wp_footer', [ self::class, 'emit' ], PHP_INT_MAX );
	}

	// -------------------------------------------------------------------------
	// Emission
	// -------------------------------------------------------------------------

	/**
	 * wp_footer @ PHP_INT_MAX. Echoes the island, at most once per request.
	 */
	public static function emit(): void {
		if ( ! self::$armed || self::$emitted ) {
			return;
		}
		self::$emitted = true;

		$json = self::build_json();
		if ( $json === '' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $json comes from wp_json_encode() with JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT, which escapes < > & ' " to \uXXXX; no registry value can break out of the script element. esc_* would corrupt the JSON the worker parses.
		echo '<script type="application/json" id="cu-dep-graph">' . $json . '</script>';
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the encoded island payload.
	 *
	 * @return string Encoded JSON, or '' when nothing may be emitted.
	 */
	private static function build_json(): string {
		$scripts = [];
		$dropped = 0;

		foreach ( self::registered_scripts() as $raw_handle => $dep ) {
			// PHP casts numeric-string array keys to int — normalise before matching.
			$handle = (string) $raw_handle;

			if ( ! preg_match( self::HANDLE_RE, $handle ) ) {
				++$dropped;
				continue;
			}

			$deps = [];
			foreach ( (array) ( $dep->deps ?? [] ) as $raw_dep ) {
				$dep_handle = is_scalar( $raw_dep ) ? (string) $raw_dep : '';
				if ( preg_match( self::HANDLE_RE, $dep_handle ) ) {
					$deps[] = $dep_handle;
					continue;
				}
				++$dropped;
			}

			$src = $dep->src ?? false;

			$scripts[ $handle ] = [
				'd' => $deps,
				'a' => ( is_string( $src ) && $src !== '' ) ? 0 : 1,
			];
		}

		$json = wp_json_encode(
			[
				'v'         => 1,
				'dropped'   => $dropped,
				'truncated' => 0,
				'scripts'   => $scripts,
			],
			self::JSON_FLAGS
		);

		// Invalid UTF-8 anywhere in the registry -> emit nothing.
		if ( ! is_string( $json ) ) {
			return '';
		}

		if ( strlen( $json ) > self::CAP_BYTES ) {
			$json = wp_json_encode( [ 'v' => 1, 'truncated' => 1 ], self::JSON_FLAGS );
			if ( ! is_string( $json ) ) {
				return '';
			}
		}

		return $json;
	}

	/**
	 * The WP_Scripts registry, defensively normalised.
	 *
	 * @return array<string, object>
	 */
	private static function registered_scripts(): array {
		if ( ! function_exists( 'wp_scripts' ) ) {
			return [];
		}

		$wp_scripts = wp_scripts();
		if ( ! is_object( $wp_scripts ) || ! isset( $wp_scripts->registered ) || ! is_array( $wp_scripts->registered ) ) {
			return [];
		}

		return $wp_scripts->registered;
	}
}

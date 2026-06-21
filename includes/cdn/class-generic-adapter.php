<?php
/**
 * Generic detect-only CDN adapter — parameterized, unvalidated integration.
 *
 * Serves CDNs (BunnyCDN, Fastly, Akamai, Sucuri, etc.) where the rate-limit
 * exemption flow is known to vary or is undocumented. The adapter emits a
 * conditional instruction rather than imperative steps.
 *
 * @package CUScanner\Cdn
 */

namespace CUScanner\Cdn;

defined( 'ABSPATH' ) || exit;

/**
 * A parameterized adapter for detect-only / unvalidated CDN integrations.
 *
 * Construct with a CDN name and a matcher callable that inspects response
 * headers and returns true when the CDN is detected.
 *
 * All four methods required by AdapterInterface are implemented:
 * - supportsRateLimitSkip() → false  (we don't know the provider's mechanism)
 * - isValidated()           → false  (integration not confirmed against real traffic)
 */
final class GenericAdapter implements AdapterInterface {

    /** @var callable(array<string,string>):bool */
    private $matcher;

    /**
     * @param string   $name    CDN identifier, e.g. 'bunnycdn'.
     * @param callable $matcher Receives lower-cased headers array, returns bool.
     */
    public function __construct( private string $name, callable $matcher ) {
        $this->matcher = $matcher;
    }

    // ── AdapterInterface ─────────────────────────────────────────────────────

    public function name(): string {
        return $this->name;
    }

    /**
     * @param array<string,string> $headers Lower-cased header name => value.
     */
    public function detect( array $headers ): bool {
        return (bool) ( $this->matcher )( $headers );
    }

    public function supportsRateLimitSkip(): bool {
        return false; // rate-limit exemption mechanism unknown for this CDN
    }

    public function isValidated(): bool {
        return false; // integration not confirmed against provider documentation
    }

    /**
     * Returns a conditional, honest HTML instruction for unvalidated CDNs.
     *
     * The per-site secret is escaped with a single esc_html() at the output
     * point — never pre-encoded and echoed raw, never double-escaped.
     *
     * @param string $scanner_secret The per-site secret from plugin settings.
     */
    public function instructionsHtml( string $scanner_secret ): string {
        return '<p>If your CDN supports rate-limit rules that match request headers, '
            . 'exempt requests carrying <code>x-cu-scanner: ' . esc_html( $scanner_secret ) . '</code> '
            . 'from rate limiting. '
            . '(This CDN integration is unvalidated — verify the steps against your provider\'s docs.)</p>';
    }
}

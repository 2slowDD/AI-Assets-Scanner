<?php
/**
 * Cloudflare CDN adapter — rate-limit exemption via WAF custom rule.
 *
 * @package CUScanner\Cdn
 */

namespace CUScanner\Cdn;

defined( 'ABSPATH' ) || exit;

/**
 * Detects Cloudflare and emits customer-facing WAF bypass instructions.
 *
 * The adapter walks the REAL Cloudflare dashboard path so operators can create
 * a custom rule that lets the CU Scanner header skip rate-limiting and WAF
 * checks, preventing 429 / 403 responses during scans.
 */
final class CloudflareAdapter implements AdapterInterface {

    // ── AdapterInterface ─────────────────────────────────────────────────────

    public function name(): string {
        return 'cloudflare';
    }

    /**
     * @param array<string,string> $headers Lower-cased header name => value.
     */
    public function detect( array $headers ): bool {
        if ( isset( $headers['cf-ray'] ) ) {
            return true;
        }
        return isset( $headers['server'] )
            && stripos( $headers['server'], 'cloudflare' ) !== false;
    }

    public function supportsRateLimitSkip(): bool {
        return true;
    }

    public function isValidated(): bool {
        return true;
    }

    /**
     * Returns styled, customer-facing HTML instructions for creating a
     * Cloudflare WAF custom rule that lets the CU Scanner bypass rate limits.
     *
     * All dynamic output ($scanner_secret) is escaped with esc_html().
     * Static instruction text is plugin-authored and trusted.
     *
     * @param string $scanner_secret The per-site secret from plugin settings.
     */
    public function instructionsHtml( string $scanner_secret ): string {
        // Build the raw expression with real quotes — escaping happens at output time.
        $expression = 'http.request.headers["x-cu-scanner"][0] eq "' . $scanner_secret . '"';

        ob_start();
        ?>
<div class="cu-cdn-card cu-cdn-card--cloudflare">
    <h3 class="cu-cdn-card__title">Cloudflare WAF — Rate Limiting Exemption</h3>
    <p>Create a custom WAF rule so Cloudflare skips its security checks when the
    CU Scanner sends requests. This prevents 429 / 403 errors during scans.</p>

    <h4>Step 1 — Open Custom Rules</h4>
    <p>In the Cloudflare dashboard: <strong>Security &rarr; Security rules &rarr; Create rule &rarr; Custom rules</strong>.</p>

    <h4>Step 2 — Name the rule</h4>
    <p>Give it a descriptive name such as <em>CU Scanner bypass</em>.</p>

    <h4>Step 3 — Paste the expression</h4>
    <p>Switch to <em>Edit expression</em> (plain text mode) and paste:</p>
    <div class="cu-cdn-expression-wrap">
        <code id="cu-cf-rule-expression" class="cu-cdn-expression"><?php echo esc_html( $expression ); ?></code>
        <button type="button" id="cu-copy-cf-expression" class="button button-secondary cu-cdn-copy-btn">Copy</button>
    </div>

    <h4>Step 4 — Configure the action</h4>
    <p>Set <strong>Action = Skip</strong> and check <strong>all</strong> of the following components to skip:</p>
    <ul class="cu-cdn-skip-list">
        <li>All remaining custom rules</li>
        <li>All rate limiting rules</li>
        <li>All managed rules</li>
        <li>All Super Bot Fight Mode Rules</li>
        <li><strong>Browser Integrity Check</strong> <span class="cu-cdn-note">(blocks headless browsers on free plans)</span></li>
        <li><strong>Rate limiting rules (Previous version)</strong> <span class="cu-cdn-note">(legacy engine — check if visible)</span></li>
        <li>Security Level <span class="cu-cdn-note">(optional but recommended)</span></li>
    </ul>

    <h4>Step 5 — Set placement and deploy</h4>
    <p>Set <strong>Place at = First</strong>, then click <strong>Deploy</strong>.</p>

    <div class="cu-cdn-callout cu-cdn-callout--warning">
        <strong>&#9888; Free plan — Bot Fight Mode limitation</strong><br>
        A custom Skip rule <strong>cannot</strong> bypass the free <strong>Bot Fight Mode</strong> (it does
        not run on the ruleset engine — the &ldquo;All Super Bot Fight Mode Rules&rdquo; checkbox
        above does <em>not</em> cover it).<br><br>
        If 429 errors persist after deploying the rule, choose one of:
        <ul>
            <li>Temporarily <strong>disable Bot Fight Mode</strong> during scans: <em>Security &rarr; Bots &rarr; Bot Fight Mode &rarr; Off</em>.</li>
            <li>Rely on the worker&rsquo;s built-in volume-reduction feature to stay under Cloudflare&rsquo;s free-plan threshold.</li>
            <li>Check for <strong>origin-side rate limiters</strong> (Wordfence firewall, host-level rate limits) that may be triggering independently of Cloudflare.</li>
        </ul>
    </div>
</div>
        <?php
        return (string) ob_get_clean();
    }
}

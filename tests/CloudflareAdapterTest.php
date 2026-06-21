<?php

use PHPUnit\Framework\TestCase;
use CUScanner\Cdn\CloudflareAdapter;

final class CloudflareAdapterTest extends TestCase {

    private CloudflareAdapter $adapter;

    protected function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        // Stub esc_html: mirrors WordPress htmlspecialchars behavior.
        WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
            fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' )
        );
        // Stub esc_attr (same behavior as esc_html for attributes).
        WP_Mock::userFunction( 'esc_attr' )->andReturnUsing(
            fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' )
        );
        $this->adapter = new CloudflareAdapter();
    }

    protected function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ── detect() ────────────────────────────────────────────────────────────

    public function test_detects_via_cf_ray_header(): void {
        $this->assertTrue( $this->adapter->detect( [ 'cf-ray' => 'abc-LHR' ] ) );
    }

    public function test_detects_via_server_cloudflare(): void {
        $this->assertTrue( $this->adapter->detect( [ 'server' => 'cloudflare' ] ) );
    }

    public function test_detects_via_server_cloudflare_mixed_case(): void {
        $this->assertTrue( $this->adapter->detect( [ 'server' => 'Cloudflare' ] ) );
    }

    public function test_does_not_detect_nginx(): void {
        $this->assertFalse( $this->adapter->detect( [ 'server' => 'nginx' ] ) );
    }

    public function test_does_not_detect_empty_headers(): void {
        $this->assertFalse( $this->adapter->detect( [] ) );
    }

    // ── isValidated() ───────────────────────────────────────────────────────

    public function test_is_validated(): void {
        $this->assertTrue( $this->adapter->isValidated() );
    }

    // ── supportsRateLimitSkip() ─────────────────────────────────────────────

    public function test_supports_rate_limit_skip(): void {
        $this->assertTrue( $this->adapter->supportsRateLimitSkip() );
    }

    // ── name() ──────────────────────────────────────────────────────────────

    public function test_name(): void {
        $this->assertSame( 'cloudflare', $this->adapter->name() );
    }

    // ── instructionsHtml() ──────────────────────────────────────────────────

    /**
     * Secret with HTML-special characters — esc_html must encode them.
     */
    public function test_instructions_escape_secret(): void {
        $html = $this->adapter->instructionsHtml( 's3cret&<x>' );
        $this->assertStringContainsString( 's3cret&amp;&lt;x&gt;', $html );
    }

    public function test_instructions_contain_rate_limiting(): void {
        $html = $this->adapter->instructionsHtml( 'mysecret' );
        $this->assertStringContainsString( 'Rate Limiting', $html );
    }

    public function test_instructions_contain_browser_integrity_check(): void {
        $html = $this->adapter->instructionsHtml( 'mysecret' );
        $this->assertStringContainsString( 'Browser Integrity Check', $html );
    }

    public function test_instructions_contain_place_at_first(): void {
        $html = $this->adapter->instructionsHtml( 'mysecret' );
        $this->assertStringContainsString( 'First', $html );
    }

    public function test_instructions_contain_bot_fight_mode_callout(): void {
        $html = $this->adapter->instructionsHtml( 'mysecret' );
        $this->assertStringContainsString( 'Bot Fight Mode', $html );
    }

    public function test_instructions_contain_copy_button_id(): void {
        $html = $this->adapter->instructionsHtml( 'mysecret' );
        $this->assertStringContainsString( 'cu-copy-cf-expression', $html );
    }

    public function test_instructions_contain_expression_element_id(): void {
        $html = $this->adapter->instructionsHtml( 'mysecret' );
        $this->assertStringContainsString( 'cu-cf-rule-expression', $html );
    }

    public function test_instructions_contain_real_dashboard_path(): void {
        $html = $this->adapter->instructionsHtml( 'mysecret' );
        $this->assertStringContainsString( 'Security rules', $html );
        $this->assertStringContainsString( 'Custom rules', $html );
    }

    public function test_instructions_contain_action_skip(): void {
        $html = $this->adapter->instructionsHtml( 'mysecret' );
        $this->assertStringContainsString( 'Skip', $html );
    }

    public function test_instructions_contain_all_rate_limiting_rules_label(): void {
        $html = $this->adapter->instructionsHtml( 'mysecret' );
        $this->assertStringContainsString( 'All rate limiting rules', $html );
    }

    public function test_instructions_contain_super_bot_fight_mode_label(): void {
        $html = $this->adapter->instructionsHtml( 'mysecret' );
        $this->assertStringContainsString( 'All Super Bot Fight Mode Rules', $html );
    }

    public function test_instructions_secret_appears_in_expression(): void {
        $html = $this->adapter->instructionsHtml( 'tok-xyz-123' );
        $this->assertStringContainsString( 'tok-xyz-123', $html );
        // Also confirm the header expression template is present.
        $this->assertStringContainsString( 'x-cu-scanner', $html );
    }
}

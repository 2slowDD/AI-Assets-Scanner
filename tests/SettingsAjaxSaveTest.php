<?php
// tests/SettingsAjaxSaveTest.php
namespace CUScanner\Tests;

use CUScanner\Admin\SettingsAjax;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Sentinel thrown from the mocked wp_send_json_* pair so the handler's
 * "response sent" exit is observable. Deliberately NOT a bare \Exception:
 * Settings::set_railway_url() throws \RuntimeException, and a broad catch
 * here would swallow that and hide a real regression.
 */
final class SettingsAjaxJsonSent extends \Exception {
    public function __construct( public string $kind, public mixed $payload = null ) {
        parent::__construct( 'json_' . $kind );
    }
}

/**
 * Guards the authenticate-then-commit ordering in SettingsAjax::save_settings().
 *
 * These drive the REAL WpserviceClient (constructed inline inside the handler)
 * through its REAL parse() by mocking wp_remote_post + the retrieve helpers —
 * no injected client seam — so the production activation path is what is under
 * test, not a stand-in for it.
 */
final class SettingsAjaxSaveTest extends TestCase {

    private const FAKE_KEY  = 'cusk_TESTKEY_000000';
    // What admin/views/settings-page.php renders for FAKE_KEY:
    // first 6 + '•' x (len - 12) + last 6, for len > 12.
    private const FAKE_MASK = 'cusk_T•••••••000000';

    private const RAILWAY_URL = 'https://cu-scanner-railway-production.up.railway.app';

    /** @var array<int,array{0:string,1:mixed}> Every update_option() call, in order. */
    private array $writes = [];

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->writes = [];
        $_POST        = [];
    }

    public function tearDown(): void {
        $_POST = [];
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /** Nonce, capability, sanitizers, domain resolution, and the option recorder. */
    private function mock_common(): void {
        WP_Mock::userFunction( 'check_ajax_referer' )
            ->with( 'cu_scanner_settings_nonce', 'nonce' )->once()->andReturn( 1 );
        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'manage_options' )->andReturn( true );
        WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => $v );
        WP_Mock::userFunction( 'get_home_url' )->andReturn( 'https://site.test' );
        WP_Mock::userFunction( 'wp_parse_url' )
            ->andReturnUsing( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $name, $value = null ) {
                $this->writes[] = [ $name, $value ];
                return true;
            } );
        WP_Mock::userFunction( 'wp_send_json_success' )
            ->andReturnUsing( function ( $data = null ) {
                throw new SettingsAjaxJsonSent( 'success', $data );
            } );
        WP_Mock::userFunction( 'wp_send_json_error' )
            ->andReturnUsing( function ( $data = null, $code = null ) {
                throw new SettingsAjaxJsonSent( 'error', $data );
            } );
    }

    /** The auth POST comes back with $code and $body. */
    private function mock_auth_response( int $code, string $body ): void {
        WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [ 'response' => [ 'code' => $code ] ] );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( $code );
        WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
    }

    /** Runs the handler and returns the sentinel describing how it responded. */
    private function run_handler(): SettingsAjaxJsonSent {
        try {
            ( new SettingsAjax() )->save_settings();
        } catch ( SettingsAjaxJsonSent $sent ) {
            return $sent;
        }
        $this->fail( 'save_settings() returned without sending a JSON response' );
    }

    /** @return array<int,mixed> every value written to the api-key option */
    private function api_key_writes(): array {
        $vals = [];
        foreach ( $this->writes as [ $name, $value ] ) {
            if ( 'cu_scanner_api_key' === $name ) {
                $vals[] = $value;
            }
        }
        return $vals;
    }

    // ---------------------------------------------------------------------
    // Loss paths — nothing may reach cu_scanner_api_key unless auth succeeded.
    // ---------------------------------------------------------------------

    public function test_rejected_key_is_never_committed(): void {
        $this->mock_common();
        $this->mock_auth_response( 401, '{"message":"Invalid API key"}' );

        $_POST['api_key'] = 'cusk_WRONGKEY_999999';

        $sent = $this->run_handler();

        $this->assertSame( [], $this->api_key_writes(), 'a key rejected by /auth was written to the DB' );
        $this->assertSame( 'error', $sent->kind );
        $this->assertStringContainsString( 'Invalid API key', (string) $sent->payload );
    }

    public function test_mask_submitted_without_keep_sentinel_does_not_overwrite_stored_key(): void {
        $this->mock_common();
        // The mask is not a real key, so /auth rejects it exactly like a typo would.
        $this->mock_auth_response( 401, '{"message":"Invalid API key"}' );

        // Simulates admin/js/settings.js failing to swap the masked value for the
        // keep_api_key sentinel: the mask itself arrives as api_key.
        $_POST['api_key'] = self::FAKE_MASK;

        $sent = $this->run_handler();

        $this->assertSame( [], $this->api_key_writes(), 'the rendered mask overwrote the stored API key' );
        $this->assertSame( 'error', $sent->kind );
    }

    public function test_empty_key_does_not_wipe_stored_key(): void {
        $this->mock_common();

        $_POST['api_key'] = '';

        $sent = $this->run_handler();

        $this->assertSame( [], $this->api_key_writes(), 'an empty submission wiped the stored API key' );
        $this->assertSame( 'error', $sent->kind );
        $this->assertStringContainsString( 'No API key is saved.', (string) $sent->payload );
    }

    public function test_transport_failure_does_not_commit_key(): void {
        $this->mock_common();
        WP_Mock::userFunction( 'wp_remote_post' )->andReturn( new \WP_Error( 'http', 'Connection refused' ) );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( true );

        $_POST['api_key'] = self::FAKE_KEY;

        $sent = $this->run_handler();

        // Accepted residual: an unreachable wpservice.pro blocks saving a good key.
        // Recoverable by retry — unlike losing the stored one.
        $this->assertSame( [], $this->api_key_writes(), 'key committed while the API was unreachable' );
        $this->assertSame( 'error', $sent->kind );
        $this->assertStringContainsString( 'Connection refused', (string) $sent->payload );
    }

    public function test_keep_sentinel_reuses_stored_key_without_rewriting_it(): void {
        $this->mock_common();
        WP_Mock::userFunction( 'get_option' )
            ->with( 'cu_scanner_api_key', '' )->andReturn( self::FAKE_KEY );
        $this->mock_auth_response(
            200,
            '{"balance":12,"railway_url":"' . self::RAILWAY_URL . '"}'
        );

        $_POST['keep_api_key'] = '1';

        $sent = $this->run_handler();

        $this->assertSame( [], $this->api_key_writes(), 'keep_api_key path rewrote the API key option' );
        $this->assertSame( 'success', $sent->kind );
        $this->assertSame( 12, $sent->payload['credits'] );
    }

    // ---------------------------------------------------------------------
    // Negative control — the fix must not block correct work.
    // ---------------------------------------------------------------------

    public function test_valid_new_key_is_committed_once_with_railway_url(): void {
        $this->mock_common();
        $this->mock_auth_response(
            200,
            '{"balance":42,"railway_url":"' . self::RAILWAY_URL . '"}'
        );

        $_POST['api_key'] = 'cusk_NEWKEY_111111';

        $sent = $this->run_handler();

        $this->assertSame( [ 'cusk_NEWKEY_111111' ], $this->api_key_writes(), 'a valid new key was not committed exactly once' );
        $this->assertContains( [ 'cu_scanner_railway_url', self::RAILWAY_URL ], $this->writes );
        $this->assertSame( 'success', $sent->kind );
        $this->assertSame( 42, $sent->payload['credits'] );
        $this->assertSame( self::RAILWAY_URL, $sent->payload['railway_url'] );
    }

    public function test_unvalidated_options_still_persist_before_authentication(): void {
        // Pins the deliberate asymmetry: omit_cu_bypass and http-auth are not
        // decided by authenticate(), so they stay pre-auth even when auth fails.
        $this->mock_common();
        $this->mock_auth_response( 401, '{"message":"Invalid API key"}' );

        $_POST['api_key']        = 'cusk_WRONGKEY_999999';
        $_POST['omit_cu_bypass'] = '1';

        $sent = $this->run_handler();

        $this->assertContains( [ 'cu_scanner_omit_cu_bypass', '1' ], $this->writes );
        $this->assertSame( [], $this->api_key_writes() );
        $this->assertSame( 'error', $sent->kind );
    }

    // ---------------------------------------------------------------------
    // FU-M — a response without railway_url must not fatal.
    // ---------------------------------------------------------------------

    public function test_auth_success_without_railway_url_commits_key_and_succeeds(): void {
        $this->mock_common();
        $this->mock_auth_response( 200, '{"balance":5}' );

        $_POST['api_key'] = 'cusk_NEWKEY_111111';

        $sent = $this->run_handler();

        $this->assertSame( [ 'cusk_NEWKEY_111111' ], $this->api_key_writes(), 'authenticated key was not committed when railway_url was absent' );
        $this->assertNotContains( 'cu_scanner_railway_url', array_column( $this->writes, 0 ) );
        $this->assertSame( 'success', $sent->kind );
        $this->assertSame( 5, $sent->payload['credits'] );
        $this->assertSame( '', $sent->payload['railway_url'] );
    }

    public function test_auth_success_with_null_railway_url_commits_key_and_succeeds(): void {
        // The key-present-but-null shape. Unlike the absent-key case above it
        // raises no "undefined array key" warning, so an unguarded dereference
        // reaches set_railway_url( string ) and throws the raw TypeError that
        // production would fatal on — the faithful reproduction of FU-M.
        $this->mock_common();
        $this->mock_auth_response( 200, '{"balance":5,"railway_url":null}' );

        $_POST['api_key'] = 'cusk_NEWKEY_111111';

        $sent = $this->run_handler();

        $this->assertSame( [ 'cusk_NEWKEY_111111' ], $this->api_key_writes() );
        $this->assertNotContains( 'cu_scanner_railway_url', array_column( $this->writes, 0 ) );
        $this->assertSame( 'success', $sent->kind );
        $this->assertSame( '', $sent->payload['railway_url'] );
    }

    public function test_auth_success_without_balance_reports_zero_credits(): void {
        // The other unguarded dereference of $auth, same class as FU-M but not
        // fatal. Note the symptom differs by environment and the guard fixes
        // both: here PHPUnit converts the "undefined array key" warning into a
        // RuntimeException that the handler's own catch turns into an error
        // response, so an unguarded read reds this test on `kind`; in
        // production the warning is only logged and null goes on the wire,
        // which admin/js/settings.js renders as "Credit balance: null".
        $this->mock_common();
        $this->mock_auth_response( 200, '{"railway_url":"' . self::RAILWAY_URL . '"}' );

        $_POST['api_key'] = 'cusk_NEWKEY_111111';

        $sent = $this->run_handler();

        $this->assertSame( [ 'cusk_NEWKEY_111111' ], $this->api_key_writes(), 'authenticated key was not committed when balance was absent' );
        $this->assertSame( 'success', $sent->kind );
        $this->assertSame( 0, $sent->payload['credits'], 'a missing balance put null on the wire instead of 0' );
        $this->assertSame( self::RAILWAY_URL, $sent->payload['railway_url'] );
    }

    // ---------------------------------------------------------------------
    // FU-O — a railway_url we REFUSE to store is not a failed save.
    //
    // set_railway_url() throws RuntimeException on a host outside the allowlist, and that
    // throw used to escape to the handler's outer catch, which answers wp_send_json_error().
    // But the key is authenticated and committed BEFORE that point, so the user was told
    // "Refused to store Railway URL..." about a save that had in fact stored their key —
    // the one thing they opened the form to do. The behaviour was safe; the message lied.
    //
    // The value comes from the SaaS auth response, NOT from the user, so there is no user
    // action to prompt for. A URL we distrust is therefore treated exactly like an ABSENT
    // one (the case pinned above): cached URL untouched, save reports success.
    // ---------------------------------------------------------------------

    public function test_auth_success_with_rejected_railway_url_commits_key_and_succeeds(): void {
        $this->mock_common();
        $this->mock_auth_response( 200, '{"balance":5,"railway_url":"https://evil.example.com"}' );

        $_POST['api_key'] = 'cusk_NEWKEY_111111';

        $sent = $this->run_handler();

        $this->assertSame(
            [ 'cusk_NEWKEY_111111' ],
            $this->api_key_writes(),
            'the authenticated key must still be committed when the railway_url is rejected'
        );
        $this->assertNotContains(
            'cu_scanner_railway_url',
            array_column( $this->writes, 0 ),
            'a host outside the allowlist must never be stored'
        );
        $this->assertSame(
            'success',
            $sent->kind,
            'FU-O: an error here told the user their settings did not save, when their key did'
        );
        $this->assertSame( 5, $sent->payload['credits'] );
        $this->assertSame(
            '',
            $sent->payload['railway_url'],
            'the response must not advertise a URL we refused to store'
        );
    }
}

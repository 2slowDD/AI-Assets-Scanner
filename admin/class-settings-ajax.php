<?php
namespace CUScanner\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use CUScanner\Settings;
use CUScanner\Api\WpserviceClient;

class SettingsAjax {
    public function register(): void {
        add_action( 'wp_ajax_cu_scanner_save_settings', [ $this, 'save_settings' ] );
        add_action( 'wp_ajax_cu_scanner_fetch_balance', [ $this, 'fetch_balance' ] );
        add_action( 'wp_ajax_cu_scanner_ack_cdn', [ $this, 'ack_cdn' ] );
    }

    public function ack_cdn(): void {
        check_ajax_referer( 'cu_scanner_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $cdn = sanitize_text_field( wp_unslash( $_POST['cdn'] ?? '' ) );
        ( new \CUScanner\Settings() )->set_acknowledged_cdn( $cdn );
        wp_send_json_success();
    }

    public function save_settings(): void {
        check_ajax_referer( 'cu_scanner_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $settings = new Settings();

        // Checkbox: only its PRESENCE is read, never its value. FormData omits
        // unchecked boxes, so absence means unchecked — hence the unconditional
        // write. A conditional one could turn the option on but never off.
        // Persisted here, before the API-key block, because that block can
        // wp_send_json_error out (no key saved, or authenticate() throwing) and
        // this option has nothing to do with API-key validity.
        $settings->set_omit_cu_bypass( isset( $_POST['omit_cu_bypass'] ) );

        $keep = ! empty( $_POST['keep_api_key'] );
        if ( $keep ) {
            $api_key = $settings->get_api_key();
        } else {
            $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
        }

        if ( '' === $api_key ) {
            wp_send_json_error( 'No API key is saved. Please enter your API key.' );
        }

        $http_user = sanitize_text_field( wp_unslash( $_POST['http_user'] ?? '' ) );
        $http_pass = sanitize_text_field( wp_unslash( $_POST['http_pass'] ?? '' ) );
        if ( $http_user && $http_pass ) {
            $settings->set_http_auth( $http_user, $http_pass );
        } elseif ( isset( $_POST['clear_http_auth'] ) ) {
            $settings->clear_http_auth();
        }

        // Authenticate FIRST, commit the key only after. A submitted key never
        // reaches cu_scanner_api_key until /auth has accepted it, so none of the
        // three ways a bad value arrives can destroy the stored one: the mask
        // this page renders back into the field, an empty submission, or a typo.
        // This handler deliberately knows NOTHING about the mask format (that
        // lives in admin/views/settings-page.php) — all three simply fail to
        // authenticate, which is the whole point of ordering it this way.
        //
        // The asymmetry with the writes above is deliberate, not an oversight:
        // omit_cu_bypass and the HTTP-auth credentials are not validated by
        // authenticate(), so gating them on it would let an unrelated call
        // decide whether they save. The API key is the one write whose validity
        // that call actually decides, so it is the one write that waits.
        //
        // HttpException::get_status_code() is deliberately NOT consulted here.
        // It can distinguish a transport failure (0) from an HTTP rejection, so
        // a "commit anyway, we merely could not reach wpservice.pro" carve-out
        // is available — and is refused. It would re-open the overwrite path
        // under exactly the condition where the user gets no feedback. The
        // accepted residual is that a good key cannot be saved while
        // wpservice.pro is unreachable: recoverable by retry, unlike key loss.
        try {
            $client = new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $api_key );
            $auth   = $client->authenticate();
            if ( ! $keep ) {
                $settings->set_api_key( $api_key );
            }
            // Guarded like fetch_balance() below. An auth response without
            // railway_url would pass null into set_railway_url( string ), and
            // the resulting TypeError is NOT a RuntimeException — it escapes
            // this catch as an uncaught fatal, and admin/js/settings.js has no
            // .catch() for the 500, so the form silently does nothing. The key
            // HAS authenticated by this point, so an absent railway_url is a
            // success that simply leaves the cached URL untouched.
            $railway_url = ! empty( $auth['railway_url'] ) ? (string) $auth['railway_url'] : '';
            if ( '' !== $railway_url ) {
                $settings->set_railway_url( $railway_url );
            }
            // balance is guarded for the same reason and in the same style as
            // fetch_balance() below: an auth response without it would emit an
            // "undefined array key" warning and put null on the wire, which
            // admin/js/settings.js renders as "Credit balance: null". Not fatal
            // like the railway_url case, but the same defect class, so the two
            // dereferences of $auth are guarded together rather than one each.
            wp_send_json_success( [ 'credits' => $auth['balance'] ?? 0, 'railway_url' => $railway_url ] );
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    public function fetch_balance(): void {
        check_ajax_referer( 'cu_scanner_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $settings = new Settings();
        if ( $settings->has_pending_free_key() ) {
            ( new \CUScanner\FreeKeyBootstrap() )->run();
            if ( $settings->has_pending_free_key() ) {
                wp_send_json_error( 'Free API key activation is pending. Please try again later.' );
            }
        }
        try {
            $api_key = $settings->get_api_key();
            $updated = false;

            if ( $settings->is_free_key( $api_key ) ) {
                try {
                    $claim = ( new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $api_key ) )
                        ->claim_paid_key( $api_key, $settings->get_paid_key_claim_token() );
                    $claimed_key = sanitize_text_field( (string) ( $claim['api_key'] ?? '' ) );
                    if ( '' !== $claimed_key && ! $settings->is_free_key( $claimed_key ) && ! $settings->is_pending_free_key( $claimed_key ) ) {
                        $settings->set_api_key( $claimed_key );
                        $api_key = $claimed_key;
                        $updated = true;
                    }
                } catch ( \RuntimeException $e ) {
                    // Paid-key claim is best-effort; balance fetch below reports the current key state.
                }
            }

            $client  = new WpserviceClient( CU_SCANNER_WPSERVICE_URL, $api_key );
            $balance = $client->get_credits();
            if ( $updated ) {
                $auth = $client->authenticate();
                if ( ! empty( $auth['railway_url'] ) ) {
                    $settings->set_railway_url( $auth['railway_url'] );
                }
                $balance = [ 'balance' => (int) ( $auth['balance'] ?? ( $balance['balance'] ?? 0 ) ) ];
            }
            $balance['api_key_updated'] = $updated;
            wp_send_json_success( $balance );
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }
}

<?php
namespace CUScanner;

class Settings {
    public function get_api_key(): string {
        return (string) get_option( 'cu_scanner_api_key', '' );
    }

    public function set_api_key( string $key ): void {
        update_option( 'cu_scanner_api_key', $key );
    }

    public function get_railway_url(): string {
        return (string) get_option( 'cu_scanner_railway_url', '' );
    }

    public function set_railway_url( string $url ): void {
        update_option( 'cu_scanner_railway_url', $url );
    }

    public function set_http_auth( string $username, string $password ): void {
        $payload   = json_encode( [ 'username' => $username, 'password' => $password ] );
        $key       = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
        $iv        = random_bytes( 16 );
        $encrypted = openssl_encrypt( $payload, 'AES-256-CBC', $key, 0, $iv );
        update_option( 'cu_scanner_http_auth', base64_encode( $iv ) . ':' . $encrypted );
    }

    public function get_http_auth(): ?array {
        $stored = (string) get_option( 'cu_scanner_http_auth', '' );
        if ( ! $stored ) return null;
        [ $iv_b64, $encrypted ] = explode( ':', $stored, 2 );
        $key  = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
        $iv   = base64_decode( $iv_b64 );
        $json = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );
        return $json ? json_decode( $json, true ) : null;
    }

    public function clear_http_auth(): void {
        delete_option( 'cu_scanner_http_auth' );
    }

    public function get_scanner_secret(): string {
        $secret = (string) get_option( 'cu_scanner_secret', '' );
        if ( ! $secret ) {
            $secret = wp_generate_uuid4();
            update_option( 'cu_scanner_secret', $secret, false );
        }
        return $secret;
    }
}

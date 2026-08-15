<?php
namespace CUScanner\Admin;

defined( 'ABSPATH' ) || exit;

class PrivateUpdater {
    private const SLUG           = 'ai-assets-scanner';
    private const NAME           = 'AI Assets Scanner';
    private const PRODUCT_URL    = 'https://wpservice.pro/our-products/ai-assets-scanner/';
    private const MANIFEST_URL   = 'https://updates.wpservice.pro/ai-assets-scanner/stable.json';
    private const UPDATE_BASE    = 'https://updates.wpservice.pro/ai-assets-scanner/releases/';
    // NOTE: there is deliberately no UPDATED_AT constant. The release date comes from the
    // manifest's released_at and nowhere else — a compiled-in date has no way to move when a
    // release ships (build-release.py never rewrote it) and silently goes stale.
    private const MANIFEST_CACHE_KEY = 'cu_scanner_updater_manifest_v1';
    private const REQUIRES_WP    = '6.2';
    private const TESTED_WP      = '7.0.4';
    private const REQUIRES_PHP   = '8.0';

    private static ?array $manifest_for_testing = null;
    private ?array $manifest_cache = null;

    public function __construct(
        private readonly string $plugin_file,
        private readonly string $current_version
    ) {}

    public static function set_manifest_for_testing( ?array $manifest ): void {
        self::$manifest_for_testing = $manifest;
    }

    public function register(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'filter_update_transient' ] );
        add_filter( 'site_transient_update_plugins', [ $this, 'filter_existing_update_transient' ] );
        add_filter( 'plugins_api', [ $this, 'filter_plugin_information' ], 10, 3 );
        add_filter( 'plugin_row_meta', [ $this, 'filter_plugin_row_meta' ], 10, 2 );
        add_filter( 'upgrader_pre_download', [ $this, 'filter_pre_download' ], 10, 4 );
    }

    public function filter_update_transient( mixed $transient ): mixed {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }
        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = [];
        }
        $this->remove_stale_response( $transient );

        $manifest = $this->get_publishable_manifest();
        if ( null === $manifest ) {
            return $transient;
        }

        $transient->response[ $this->plugin_file ] = $this->build_update_object( $manifest );
        return $transient;
    }

    public function filter_existing_update_transient( mixed $transient ): mixed {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }
        return $this->remove_stale_response( $transient );
    }

    public function filter_plugin_information( mixed $result, string $action, object $args ): mixed {
        if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== self::SLUG ) {
            return $result;
        }

        $manifest = $this->get_manifest() ?? [];
        $sections = isset( $manifest['sections'] ) && is_array( $manifest['sections'] )
            ? $manifest['sections']
            : [];

        return (object) [
            'name'          => self::NAME,
            'slug'          => self::SLUG,
            'version'       => (string) ( $manifest['version'] ?? $this->current_version ),
            'author'        => '<a href="https://wpservice.pro/">WPservice.pro</a>',
            'homepage'      => self::PRODUCT_URL,
            'requires'      => (string) ( $manifest['requires_wp'] ?? self::REQUIRES_WP ),
            'tested'        => (string) ( $manifest['tested_wp'] ?? self::TESTED_WP ),
            'requires_php'  => (string) ( $manifest['requires_php'] ?? self::REQUIRES_PHP ),
            // WP's plugin-details modal renders last_updated; it was never set here, so the
            // modal showed no "Last Updated" at all. Derived from the manifest's released_at.
            'last_updated'  => $this->last_updated_for_api(),
            'icons'         => $this->icons(),
            'download_link' => isset( $manifest['download_url'] ) && $this->is_allowed_package_url( (string) $manifest['download_url'] )
                ? (string) $manifest['download_url']
                : '',
            'sections'      => [
                'description' => $this->safe_html( (string) ( $sections['description'] ?? 'AI-powered CSS/JS asset scanner for WordPress.' ) ),
                'changelog'   => $this->safe_html( (string) ( $sections['changelog'] ?? 'See the product page for release notes.' ) ),
            ],
        ];
    }

    public function filter_plugin_row_meta( array $links, string $file ): array {
        if ( $file !== $this->plugin_file ) {
            return $links;
        }

        // Manifest-first, compiled-in constants only as fallback — the same precedence
        // filter_plugin_information() already used. Until 1.7.93b these three lines rendered
        // raw constants, so the row advertised a release date and a "Tested upto" that no
        // release could ever move.
        $manifest = $this->cached_manifest() ?? [];
        $updated  = $this->updated_at_display();

        $links[] = '<a href="' . esc_url( self::PRODUCT_URL ) . '" target="_blank" rel="noopener">View details</a>';
        // Omitted entirely when the manifest carries no valid date. A missing field is honest;
        // a hardcoded one goes stale silently, which is exactly the bug this replaced.
        if ( '' !== $updated ) {
            $links[] = 'Updated: <strong>' . esc_html( $updated ) . '</strong>';
        }
        $links[] = 'Requires at least: <strong>v' . esc_html( $this->sane_version( $manifest['requires_wp'] ?? null, self::REQUIRES_WP ) ) . '</strong>';
        $links[] = 'Tested upto: <strong>v' . esc_html( $this->sane_version( $manifest['tested_wp'] ?? null, self::TESTED_WP ) ) . '</strong>';
        $links[] = 'Status: <span style="color:#2271b1">Available</span>';
        return $links;
    }

    public function filter_pre_download( mixed $reply, string $package, mixed $upgrader, array $hook_extra ): mixed {
        if ( false !== $reply || ! $this->is_aas_package( $package, $hook_extra ) ) {
            return $reply;
        }

        $expected = $this->expected_checksum_for_package( $package );
        if ( '' === $expected ) {
            return new \WP_Error( 'aias_checksum_missing', 'AI Assets Scanner update package checksum is missing.' );
        }

        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $download = download_url( $package, 300 );
        if ( is_wp_error( $download ) ) {
            return $download;
        }

        $actual = hash_file( 'sha256', $download );
        if ( ! is_string( $actual ) || ! hash_equals( strtolower( $expected ), strtolower( $actual ) ) ) {
            wp_delete_file( $download );
            return new \WP_Error( 'aias_checksum_mismatch', 'AI Assets Scanner update package checksum did not match the signed manifest.' );
        }

        return $download;
    }

    private function get_publishable_manifest(): ?array {
        $manifest = $this->get_manifest();
        if ( null === $manifest ) {
            return null;
        }
        if ( empty( $manifest['published'] ) ) {
            return null;
        }
        if ( empty( $manifest['version'] ) || ! is_string( $manifest['version'] ) ) {
            return null;
        }
        if ( ! version_compare( $manifest['version'], $this->current_version, '>' ) ) {
            return null;
        }
        if ( empty( $manifest['download_url'] ) || ! $this->is_allowed_package_url( (string) $manifest['download_url'] ) ) {
            return null;
        }
        if ( empty( $manifest['sha256'] ) || ! $this->is_valid_sha256( (string) $manifest['sha256'] ) ) {
            return null;
        }
        return $manifest;
    }

    private function remove_stale_response( object $transient ): object {
        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            return $transient;
        }
        if ( ! isset( $transient->response[ $this->plugin_file ] ) || ! is_object( $transient->response[ $this->plugin_file ] ) ) {
            return $transient;
        }

        $new_version = $transient->response[ $this->plugin_file ]->new_version ?? null;
        if ( is_string( $new_version ) && ! version_compare( $new_version, $this->current_version, '>' ) ) {
            unset( $transient->response[ $this->plugin_file ] );
        }

        return $transient;
    }

    private function get_manifest(): ?array {
        if ( null !== self::$manifest_for_testing ) {
            return self::$manifest_for_testing;
        }
        if ( null !== $this->manifest_cache ) {
            return $this->manifest_cache;
        }

        $response = wp_remote_get( self::MANIFEST_URL, [
            'headers' => [ 'Accept' => 'application/json' ],
            'timeout' => 10,
        ] );
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }

        $this->manifest_cache = $decoded;
        // Persist for the DISPLAY path only (see cached_manifest()). The update check itself
        // never reads this — it always fetches — so update freshness is unchanged. Refreshed on
        // every successful check (WP runs those twice daily), so it self-heals the moment a new
        // release lands.
        set_transient( self::MANIFEST_CACHE_KEY, $decoded, WEEK_IN_SECONDS );
        return $this->manifest_cache;
    }

    /**
     * Manifest for RENDER paths — never performs HTTP.
     *
     * filter_plugin_row_meta() runs on every Plugins-screen paint. Calling get_manifest() there
     * would put a blocking 10s-timeout remote GET in a page render, so display code reads only
     * what a previous update check already fetched. No cached copy → callers omit the field
     * rather than fetch or invent one.
     */
    private function cached_manifest(): ?array {
        if ( null !== self::$manifest_for_testing ) {
            return self::$manifest_for_testing;
        }
        if ( null !== $this->manifest_cache ) {
            return $this->manifest_cache;
        }
        $cached = get_transient( self::MANIFEST_CACHE_KEY );
        return is_array( $cached ) ? $cached : null;
    }

    private function build_update_object( array $manifest ): object {
        return (object) [
            'id'            => self::PRODUCT_URL,
            'slug'          => self::SLUG,
            'plugin'        => $this->plugin_file,
            'new_version'   => (string) $manifest['version'],
            'url'           => self::PRODUCT_URL,
            'package'       => (string) $manifest['download_url'],
            'tested'        => (string) ( $manifest['tested_wp'] ?? self::TESTED_WP ),
            'requires'      => (string) ( $manifest['requires_wp'] ?? self::REQUIRES_WP ),
            'requires_php'  => (string) ( $manifest['requires_php'] ?? self::REQUIRES_PHP ),
            'sha256'        => strtolower( (string) $manifest['sha256'] ),
            'icons'         => $this->icons(),
            'banners'       => [],
            'compatibility' => new \stdClass(),
        ];
    }

    private function icons(): array {
        $url = \CU_SCANNER_URL . 'admin/images/ai-assets-scanner-logo.png';
        return [
            '1x'      => $url,
            '2x'      => $url,
            'default' => $url,
        ];
    }

    private function is_aas_package( string $package, array $hook_extra ): bool {
        if ( ( $hook_extra['plugin'] ?? '' ) === $this->plugin_file ) {
            return true;
        }
        return $this->is_allowed_package_url( $package );
    }

    private function expected_checksum_for_package( string $package ): string {
        $manifest = $this->get_manifest();
        if (
            null === $manifest
            || empty( $manifest['published'] )
            || empty( $manifest['download_url'] )
            || (string) $manifest['download_url'] !== $package
            || empty( $manifest['sha256'] )
            || ! $this->is_valid_sha256( (string) $manifest['sha256'] )
        ) {
            return '';
        }
        return strtolower( (string) $manifest['sha256'] );
    }

    private function is_allowed_package_url( string $url ): bool {
        return str_starts_with( $url, self::UPDATE_BASE ) && str_ends_with( $url, '/ai-assets-scanner.zip' );
    }

    private function is_valid_sha256( string $hash ): bool {
        return 1 === preg_match( '/\A[a-f0-9]{64}\z/i', $hash );
    }

    /**
     * Validated release timestamp from the manifest, or null when absent/malformed.
     *
     * The manifest is fetched from a remote host (self::MANIFEST_URL), so released_at is
     * third-party input and is validated by SHAPE before use, not merely escaped at render
     * (wp-compliance rule 1: validate over sanitize when the allowed form is known).
     * The regex pins exactly the ISO-8601 UTC form build-release.py emits; a bare strtotime()
     * would cheerfully accept 'now' or 'tomorrow' from a tampered manifest and render a date
     * that moves on every page load.
     */
    private function manifest_released_ts(): ?int {
        // cached_manifest(), never get_manifest(): this feeds render paths. In the
        // plugin-information modal the in-memory cache is already warm from the fetch at the
        // top of that method, so the modal still gets a live value.
        $manifest = $this->cached_manifest();
        $raw      = is_array( $manifest ) ? ( $manifest['released_at'] ?? null ) : null;
        if ( ! is_string( $raw ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $raw ) ) {
            return null;
        }
        $ts = strtotime( $raw );
        return is_int( $ts ) ? $ts : null;
    }

    /**
     * Human-readable release date for the plugins-list row; '' when unknown (caller omits the row).
     *
     * gmdate, not wp_date: released_at is a UTC date-only concept, so a site on a negative
     * timezone offset would render midnight-UTC as the PREVIOUS day.
     */
    private function updated_at_display(): string {
        $ts = $this->manifest_released_ts();
        return null === $ts ? '' : gmdate( 'F j, Y', $ts );
    }

    /** WordPress renders last_updated as 'Y-m-d H:i:s'; '' when unknown so core omits the field. */
    private function last_updated_for_api(): string {
        $ts = $this->manifest_released_ts();
        return null === $ts ? '' : gmdate( 'Y-m-d H:i:s', $ts );
    }

    /**
     * Validate a manifest version string, falling back to the compiled-in constant.
     * Remote input again (rule 1): a version is a known shape, so validate it rather than
     * trusting escaping alone to make arbitrary text presentable.
     */
    private function sane_version( mixed $value, string $fallback ): string {
        return ( is_string( $value ) && 1 === preg_match( '/^\d+(\.\d+){0,3}$/', $value ) )
            ? $value
            : $fallback;
    }

    /** @internal test seams */
    public function __test_updated_at_display(): string { return $this->updated_at_display(); }
    public function __test_last_updated_for_api(): string { return $this->last_updated_for_api(); }

    private function safe_html( string $value ): string {
        if ( function_exists( 'wp_kses_post' ) ) {
            $safe = wp_kses_post( $value );
            return is_string( $safe ) ? $safe : '';
        }
        return strip_tags( $value, '<p><br><strong><em><ul><ol><li><code><pre><a><h2><h3>' );
    }
}

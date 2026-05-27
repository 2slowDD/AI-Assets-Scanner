<?php

use WP_Mock\Tools\TestCase;

class UninstallTest extends TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_uninstall_preserves_saved_api_key(): void {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        WP_Mock::userFunction( 'current_user_can' )
            ->with( 'delete_plugins' )
            ->andReturn( true );

        WP_Mock::userFunction( 'delete_option' )
            ->with( 'cu_scanner_api_key' )
            ->never();

        WP_Mock::userFunction( 'delete_option' )
            ->with( \Mockery::not( 'cu_scanner_api_key' ) )
            ->andReturn( true )
            ->byDefault();

        $GLOBALS['wpdb'] = new class {
            public string $options = 'wp_options';

            public function esc_like( string $value ): string {
                return $value;
            }

            public function prepare( string $query, string ...$args ): string {
                return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
            }

            public function query( string $query ): int {
                return 0;
            }
        };

        require dirname( __DIR__ ) . '/uninstall.php';

        $this->assertTrue( true );
    }
}

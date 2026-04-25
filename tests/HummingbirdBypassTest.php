<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\Strategies\HummingbirdBypass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class HummingbirdBypassTest extends TestCase {

    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_slug_is_hummingbird(): void {
        $this->assertSame( 'hummingbird', ( new HummingbirdBypass() )->slug() );
    }

    public function test_snapshot_captures_minify_enabled_value(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'wphb_settings', [] )
            ->andReturn( [ 'minify' => [ 'enabled' => true, 'css_excluded' => [] ] ] );
        $snap = ( new HummingbirdBypass() )->snapshot();
        $this->assertSame( true, $snap['minify_enabled'] );
    }

    public function test_snapshot_records_null_when_minify_key_absent(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'wphb_settings', [] )
            ->andReturn( [] );
        $snap = ( new HummingbirdBypass() )->snapshot();
        $this->assertNull( $snap['minify_enabled'] );
    }

    public function test_disable_flips_minify_enabled_to_false(): void {
        $current = [ 'minify' => [ 'enabled' => true, 'css_excluded' => [ 'foo' ] ] ];
        WP_Mock::userFunction( 'get_option' )
            ->andReturnUsing( function () use ( &$current ) { return $current; } );
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$current ) {
                $current = $value;
                return true;
            } );

        ( new HummingbirdBypass() )->disable();
        $this->assertFalse( $current['minify']['enabled'] );
        $this->assertSame( [ 'foo' ], $current['minify']['css_excluded'],
            'unrelated nested keys preserved' );
    }

    public function test_disable_handles_missing_minify_section(): void {
        $current = [];
        WP_Mock::userFunction( 'get_option' )
            ->andReturnUsing( function () use ( &$current ) { return $current; } );
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$current ) {
                $current = $value;
                return true;
            } );

        ( new HummingbirdBypass() )->disable();
        $this->assertFalse( $current['minify']['enabled'] );
    }

    public function test_restore_writes_snapshot_value(): void {
        $current = [ 'minify' => [ 'enabled' => false ] ];
        WP_Mock::userFunction( 'get_option' )
            ->andReturnUsing( function () use ( &$current ) { return $current; } );
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$current ) {
                $current = $value;
                return true;
            } );

        ( new HummingbirdBypass() )->restore( [ 'minify_enabled' => true ] );
        $this->assertTrue( $current['minify']['enabled'] );
    }

    public function test_restore_unsets_when_snapshot_was_null(): void {
        $current = [ 'minify' => [ 'enabled' => false, 'css_excluded' => [] ] ];
        WP_Mock::userFunction( 'get_option' )
            ->andReturnUsing( function () use ( &$current ) { return $current; } );
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$current ) {
                $current = $value;
                return true;
            } );

        ( new HummingbirdBypass() )->restore( [ 'minify_enabled' => null ] );
        $this->assertArrayNotHasKey( 'enabled', $current['minify'] );
        $this->assertSame( [], $current['minify']['css_excluded'],
            'unrelated nested keys preserved' );
    }

    public function test_factory_returns_hummingbird_strategy(): void {
        $strategy = \CUScanner\Scanner\StrategyFactory::for_method( 'hummingbird' );
        $this->assertInstanceOf( HummingbirdBypass::class, $strategy );
        $this->assertSame( 'hummingbird', $strategy->slug() );
    }
}

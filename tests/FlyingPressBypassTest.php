<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\Strategies\FlyingPressBypass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class FlyingPressBypassTest extends TestCase {

    private const KEYS = [
        'optimize_css', 'lazy_load_css', 'remove_unused_css',
        'minify_css', 'minify_js', 'defer_js', 'lazy_load_iframes',
    ];

    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_slug_is_flying_press(): void {
        $this->assertSame( 'flying_press', ( new FlyingPressBypass() )->slug() );
    }

    public function test_snapshot_captures_all_documented_keys(): void {
        $opts = [
            'optimize_css'      => true,
            'lazy_load_css'     => true,
            'remove_unused_css' => true,
            'minify_css'        => true,
            'minify_js'         => false,
            'defer_js'          => true,
            'lazy_load_iframes' => true,
            'unrelated_setting' => 'preserve_me',
        ];
        WP_Mock::userFunction( 'get_option' )
            ->with( 'flying_press_settings', [] )
            ->andReturn( $opts );

        $snap = ( new FlyingPressBypass() )->snapshot();
        foreach ( self::KEYS as $k ) {
            $this->assertArrayHasKey( $k, $snap, "Snapshot must capture $k" );
        }
        $this->assertArrayNotHasKey( 'unrelated_setting', $snap,
            'snapshot only includes documented keys' );
    }

    public function test_snapshot_records_null_for_absent_keys(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'flying_press_settings', [] )
            ->andReturn( [ 'optimize_css' => true ] );

        $snap = ( new FlyingPressBypass() )->snapshot();
        $this->assertSame( true, $snap['optimize_css'] );
        $this->assertNull( $snap['minify_css'], 'absent keys recorded as null for restore semantics' );
    }

    public function test_disable_zeros_documented_keys_only(): void {
        $stored = [
            'optimize_css'      => true,
            'lazy_load_css'     => true,
            'minify_css'        => true,
            'minify_js'         => false,
            'unrelated_setting' => 'preserve_me',
        ];
        WP_Mock::userFunction( 'get_option' )
            ->with( 'flying_press_settings', [] )
            ->andReturn( $stored );
        $captured = null;
        WP_Mock::userFunction( 'update_option' )
            ->with( 'flying_press_settings', \Mockery::on( function ( $value ) use ( &$captured ) {
                $captured = $value;
                return true;
            } ) )
            ->andReturn( true );

        ( new FlyingPressBypass() )->disable();

        foreach ( self::KEYS as $k ) {
            $this->assertFalse( $captured[ $k ], "disable must zero $k" );
        }
        $this->assertSame( 'preserve_me', $captured['unrelated_setting'],
            'unrelated keys preserved' );
    }

    public function test_restore_byte_identical_to_snapshot(): void {
        $original = [
            'optimize_css'      => true,
            'lazy_load_css'     => false,
            'remove_unused_css' => true,
            'minify_css'        => true,
            'minify_js'         => false,
            'defer_js'          => true,
            'lazy_load_iframes' => true,
            'unrelated_setting' => 'preserve_me',
        ];
        $current = $original;
        WP_Mock::userFunction( 'get_option' )
            ->andReturnUsing( function () use ( &$current ) { return $current; } );
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$current ) {
                $current = $value;
                return true;
            } );

        $strategy = new FlyingPressBypass();
        $snap = $strategy->snapshot();
        $strategy->disable();
        $strategy->restore( $snap );
        $this->assertSame( $original, $current );
    }

    public function test_restore_idempotent_under_replay(): void {
        $original = [ 'optimize_css' => true, 'minify_css' => true ];
        $current  = $original;
        WP_Mock::userFunction( 'get_option' )
            ->andReturnUsing( function () use ( &$current ) { return $current; } );
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$current ) {
                $current = $value;
                return true;
            } );

        $strategy = new FlyingPressBypass();
        $snap = $strategy->snapshot();
        $strategy->disable();
        $strategy->restore( $snap );
        $first = $current;
        $strategy->restore( $snap );
        $this->assertSame( $first, $current );
    }

    public function test_restore_unsets_keys_that_were_absent(): void {
        // If a key was null in the snapshot (originally absent), restore should
        // unset it rather than store null.
        $current = [ 'optimize_css' => false, 'minify_css' => false ];  // disabled state
        WP_Mock::userFunction( 'get_option' )
            ->andReturnUsing( function () use ( &$current ) { return $current; } );
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$current ) {
                $current = $value;
                return true;
            } );

        // Snapshot with `minify_css` recorded as null (was absent originally).
        $snap = [ 'optimize_css' => true, 'minify_css' => null,
                  'lazy_load_css' => null, 'remove_unused_css' => null,
                  'minify_js' => null, 'defer_js' => null, 'lazy_load_iframes' => null ];

        ( new FlyingPressBypass() )->restore( $snap );
        $this->assertSame( true, $current['optimize_css'] );
        $this->assertArrayNotHasKey( 'minify_css', $current,
            'null in snapshot means key was absent — restore unsets it' );
    }
}

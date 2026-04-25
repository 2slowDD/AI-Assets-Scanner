<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\Strategies\SgOptimizerBypass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class SgOptimizerBypassTest extends TestCase {

    private const KEYS = [
        'siteground_optimizer_combine_css',
        'siteground_optimizer_minify_css',
        'siteground_optimizer_minify_javascript',
        'siteground_optimizer_combine_javascript',
        'siteground_optimizer_optimize_html_render',
        'siteground_optimizer_lazyload_images',
    ];

    public function setUp(): void { parent::setUp(); WP_Mock::setUp(); }
    public function tearDown(): void { WP_Mock::tearDown(); parent::tearDown(); }

    public function test_slug_is_sg_optimizer(): void {
        $this->assertSame( 'sg_optimizer', ( new SgOptimizerBypass() )->slug() );
    }

    public function test_snapshot_records_each_option_value(): void {
        // Stub each option independently
        $values = [
            'siteground_optimizer_combine_css'         => 1,
            'siteground_optimizer_minify_css'          => 1,
            'siteground_optimizer_minify_javascript'   => 0,
            'siteground_optimizer_combine_javascript'  => 1,
            'siteground_optimizer_optimize_html_render'=> 0,
            'siteground_optimizer_lazyload_images'     => 1,
        ];
        WP_Mock::userFunction( 'get_option' )
            ->andReturnUsing( fn( $key, $default = null ) => $values[ $key ] ?? $default );

        $snap = ( new SgOptimizerBypass() )->snapshot();
        foreach ( self::KEYS as $k ) {
            $this->assertArrayHasKey( $k, $snap );
            $this->assertSame( $values[ $k ], $snap[ $k ] );
        }
    }

    public function test_snapshot_records_null_when_option_absent(): void {
        WP_Mock::userFunction( 'get_option' )
            ->andReturn( null );
        $snap = ( new SgOptimizerBypass() )->snapshot();
        foreach ( self::KEYS as $k ) {
            $this->assertNull( $snap[ $k ] );
        }
    }

    public function test_disable_writes_zero_to_each_option(): void {
        $written = [];
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$written ) {
                $written[ $key ] = $value;
                return true;
            } );

        ( new SgOptimizerBypass() )->disable();
        foreach ( self::KEYS as $k ) {
            $this->assertSame( 0, $written[ $k ] );
        }
    }

    public function test_restore_writes_back_snapshot(): void {
        $snap = [
            'siteground_optimizer_combine_css'         => 1,
            'siteground_optimizer_minify_css'          => 0,
            'siteground_optimizer_minify_javascript'   => 1,
            'siteground_optimizer_combine_javascript'  => 0,
            'siteground_optimizer_optimize_html_render'=> 1,
            'siteground_optimizer_lazyload_images'     => 0,
        ];
        $written = [];
        WP_Mock::userFunction( 'update_option' )
            ->andReturnUsing( function ( $key, $value ) use ( &$written ) {
                $written[ $key ] = $value;
                return true;
            } );

        ( new SgOptimizerBypass() )->restore( $snap );
        foreach ( $snap as $k => $v ) {
            $this->assertSame( $v, $written[ $k ] );
        }
    }

    public function test_restore_deletes_options_that_were_absent_originally(): void {
        // null in snapshot means the option was absent before disable —
        // restore must call delete_option, not write null.
        $snap = array_fill_keys( self::KEYS, null );
        $deleted = [];
        WP_Mock::userFunction( 'delete_option' )
            ->andReturnUsing( function ( $key ) use ( &$deleted ) {
                $deleted[] = $key;
                return true;
            } );
        WP_Mock::userFunction( 'update_option' )->never();

        ( new SgOptimizerBypass() )->restore( $snap );
        foreach ( self::KEYS as $k ) {
            $this->assertContains( $k, $deleted, "must delete_option for $k" );
        }
    }

    public function test_factory_returns_sg_optimizer_strategy(): void {
        $strategy = \CUScanner\Scanner\StrategyFactory::for_method( 'sg_optimizer' );
        $this->assertInstanceOf( SgOptimizerBypass::class, $strategy );
        $this->assertSame( 'sg_optimizer', $strategy->slug() );
    }
}

<?php
use PHPUnit\Framework\TestCase;
use CUScanner\Cdn\GenericAdapter;

final class GenericAdapterTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        WP_Mock::userFunction( 'esc_html' )
            ->andReturnUsing( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
    }

    public function test_bunnycdn_detection_and_conditional_unvalidated_instruction(): void {
        $a = new GenericAdapter(
            'bunnycdn',
            fn( array $h ) => isset( $h['server'] ) && stripos( $h['server'], 'bunnycdn' ) !== false
        );

        $this->assertTrue( $a->detect( ['server' => 'BunnyCDN'] ) );
        $this->assertFalse( $a->detect( ['server' => 'cloudflare'] ) );
        $this->assertFalse( $a->isValidated() );

        $html = $a->instructionsHtml( 'sek' );
        $this->assertStringContainsString( 'If your CDN supports', $html ); // conditional, not imperative
        $this->assertStringContainsString( 'x-cu-scanner', $html );
    }
}

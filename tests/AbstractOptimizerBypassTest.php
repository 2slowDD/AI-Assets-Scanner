<?php
namespace CUScanner\Tests;

use CUScanner\Scanner\Strategies\AbstractOptimizerBypass;
use ReflectionClass;
use ReflectionMethod;

class AbstractOptimizerBypassTest extends \PHPUnit\Framework\TestCase {

    public function test_abstract_class_cannot_be_instantiated(): void {
        $reflection = new ReflectionClass( AbstractOptimizerBypass::class );
        $this->assertTrue( $reflection->isAbstract() );
    }

    public function test_required_abstract_methods(): void {
        $reflection = new ReflectionClass( AbstractOptimizerBypass::class );
        $abstract_method_names = array_map(
            static fn( ReflectionMethod $m ) => $m->getName(),
            $reflection->getMethods( ReflectionMethod::IS_ABSTRACT )
        );
        sort( $abstract_method_names );
        $this->assertSame(
            [ 'disable', 'restore', 'slug', 'snapshot' ],
            $abstract_method_names
        );
    }

    public function test_method_signatures_match_contract(): void {
        $reflection = new ReflectionClass( AbstractOptimizerBypass::class );

        $snapshot = $reflection->getMethod( 'snapshot' );
        $this->assertSame( 'array', (string) $snapshot->getReturnType() );
        $this->assertSame( 0, $snapshot->getNumberOfParameters() );

        $disable = $reflection->getMethod( 'disable' );
        $this->assertSame( 'void', (string) $disable->getReturnType() );
        $this->assertSame( 0, $disable->getNumberOfParameters() );

        $restore = $reflection->getMethod( 'restore' );
        $this->assertSame( 'void', (string) $restore->getReturnType() );
        $this->assertSame( 1, $restore->getNumberOfParameters() );
        $params = $restore->getParameters();
        $this->assertSame( 'array', (string) $params[0]->getType() );
        $this->assertSame( 'snapshot', $params[0]->getName() );

        $slug = $reflection->getMethod( 'slug' );
        $this->assertSame( 'string', (string) $slug->getReturnType() );
        $this->assertSame( 0, $slug->getNumberOfParameters() );
    }
}

<?php

use TypePhp\CompilerTest;

/**
 * An outer `??` re-parses its left operand (the preg_replace_callback call),
 * so the callback closure used to be emitted several times. Only the last
 * emission is wired into newClosureWithParameters(); the earlier generations
 * were discarded, and with them the materialized `tmp = php::exists(...)`
 * statement of the inner `??` guard, leaving the live copy reading an
 * uninitialized temporary (guard always true). Every emitted closure must
 * materialize its own `??` guard, regardless of the number of generations.
 */
final class ClosureRepeatedCoalesceCodegenTest extends \BaseTest
{
    public function testEveryClosureGenerationMaterializesCoalesceGuard(): void
    {
        $code = $this->compileFixture();

        // Repeated parsing may emit several closures. Their number is an
        // implementation detail; every emitted body needs its own assignment.
        $generations = substr_count($code, 'php::ClosureFn');
        self::assertGreaterThan(0, $generations);

        self::assertSame(
            $generations,
            substr_count($code, 'php::exists(m,'),
            'every generation of the closure must materialize its ?? guard in its own context',
        );
    }

    private function compileFixture(): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/closure-repeated-coalesce-codegen.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        return $code;
    }
}

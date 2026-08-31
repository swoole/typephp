<?php

use TypePhp\CompilerTest;

/**
 * The PHP_INT_MAX/PHP_INT_MIN constant folder must resolve the constant name
 * like PHP does: case-sensitively, and only to the real global constant. An
 * unqualified fetch inside a namespace resolves to Namespace\PHP_INT_MAX
 * first, and a lowercase php_int_max is an undefined constant, not the
 * global value.
 */
final class PhpIntMaxFoldTest extends \BaseTest
{
    public function testNamespacedConstantShadowsGlobalAndIsNotFolded(): void
    {
        $code = $this->compileFixture('php-int-max-fold-namespace.php');

        // The unqualified fetch reads the namespaced constant at runtime.
        self::assertStringContainsString('_const_var_FoldNs__PHP_INT_MAX', $code);
        // The fully qualified fetch still folds to the overflowed float.
        self::assertStringContainsString('9.2233720368547758e+18', $code);
    }

    public function testLowercaseNameIsARuntimeConstantLookup(): void
    {
        $code = $this->compileFixture('php-int-max-fold-global.php');

        // php_int_max is undefined in PHP; it must stay a runtime lookup
        // that raises the undefined-constant Error, never fold.
        self::assertStringContainsString('php::constant(', $code);
        // The exact-case global fetch keeps folding.
        self::assertStringContainsString('9.2233720368547758e+18', $code);
    }

    private function compileFixture(string $file): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/' . $file;
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        return $code;
    }
}

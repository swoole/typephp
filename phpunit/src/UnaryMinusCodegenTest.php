<?php

use TypePhp\CompilerTest;

/**
 * Unary minus must parenthesize its operand. Without the parentheses,
 * `-($a ? $b : $c)` emits `-cond ? b : c`, which C++ parses as
 * `(-cond) ? b : c`: the minus is applied to the condition instead of the
 * selected branch, and the branch choice itself can flip.
 */
final class UnaryMinusCodegenTest extends \BaseTest
{
    public function testUnaryMinusParenthesizesTernaryOperand(): void
    {
        $code = $this->compileFixture();

        self::assertStringContainsString('-((php::toBool(a)) ? (b) : (c))', $code);
        self::assertStringNotContainsString('-(php::toBool(a)) ?', $code);
    }

    public function testNestedUnaryMinusDoesNotPasteIntoPreDecrement(): void
    {
        $code = $this->compileFixture();

        self::assertStringNotContainsString('--a', $code);
    }

    private function compileFixture(): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/unary-minus-codegen.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        return $code;
    }
}

<?php

use TypePhp\CompilerTest;

final class HotPathCodegenTest extends \BaseTest
{
    public function testKnownArrayStatementWritesAvoidResultTemporaries(): void
    {
        $code = $this->compileFixture();

        self::assertStringContainsString('items.item(0L, true) = value;', $code);
        self::assertStringContainsString('items.appendValue(value);', $code);
        self::assertStringContainsString('items.item(0L, true) += value;', $code);
        self::assertStringContainsString('items.item(0L, true) += other.get(0L);', $code);
        self::assertStringContainsString('items.item(2L, true) = other.get(0L);', $code);
        // Value-producing compound writes must also retain array buckets:
        // offsetSet() would replace an existing referenced element.
        self::assertStringNotContainsString('items.offsetSet(', $code);
    }

    public function testKnownArrayAppendFallbackAvoidsDynamicDispatch(): void
    {
        $code = $this->compileFixture();

        self::assertStringNotContainsString('items.offsetSet(php::null,', $code);
        self::assertMatchesRegularExpression('/items\.append\(tmp_var_\d+\)/', $code);
    }

    public function testSafeTwoOperandConcatAndExactStringArgumentStayUnboxed(): void
    {
        $code = $this->compileFixture();

        self::assertMatchesRegularExpression(
            '/php::concat\(get_str\(\d+\), limit\)/',
            $code,
        );
        self::assertStringContainsString('php::concat({', $code);
        self::assertStringNotContainsString('php::fn::strlen(php::toString(php::concat(', $code);
    }

    public function testNativePostDecrementConditionUsesNativeTemporary(): void
    {
        $code = $this->compileFixture();

        self::assertMatchesRegularExpression('/php::Int (tmp_var_\d+) = 0;[\s\S]*?\\1 = php::toInt\(limit--\);/', $code);
        self::assertDoesNotMatchRegularExpression('/php::Var (tmp_var_\d+);[\s\S]*?\\1 = limit--;/', $code);
    }

    public function testTypedReadsAndSimpleShorthandTernariesUseFastPaths(): void
    {
        $code = $this->compileFixture();

        self::assertMatchesRegularExpression(
            '/hash\.get\(get_str\(\d+\)\)/',
            $code,
        );
        self::assertStringContainsString('str.offsetGet(0L)', $code);
        self::assertStringNotContainsString('php::notEmpty(hash, {},', $code);
        self::assertStringNotContainsString('php::notEmpty(flag, {},', $code);
        self::assertStringContainsString('php::toBool(hash)', $code);
        self::assertStringContainsString('php::toBool(flag)', $code);

        // Compound receivers retain the evaluate-once chain implementation.
        self::assertMatchesRegularExpression(
            '/php::notEmpty\(hash, \{\{php::ArrayDimFetch, php::Var\(get_str\(\d+\)\)\}\}, tmp_var_\d+\)/',
            $code,
        );
    }

    private function compileFixture(): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/hot-path-codegen.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        return $code;
    }
}

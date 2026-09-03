<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

use TypePhp\CompilerTest;

/**
 * @internal
 * @coversNothing
 */
final class ArrayAccessCoalesceAssignCodegenTest extends BaseTest
{
    public function testObjectTargetSeparatesPresenceReadAndWrite(): void
    {
        $code = $this->compileFixture();
        $body = $this->extractFunctionBody($code, 'php::Var php_coalescearrayaccess(');

        self::assertSame(1, substr_count($body, '.offsetExists('));
        self::assertSame(1, substr_count($body, '.offsetGet('));
        self::assertSame(1, substr_count($body, '.offsetSet(key,'));
        self::assertStringContainsString('.isObject()', $body);
        self::assertStringContainsString('.assignKeyedDimension(key,', $body);
    }

    public function testMixedTargetRetainsArrayAndObjectDispatch(): void
    {
        $code = $this->compileFixture();
        $body = $this->extractFunctionBody($code, 'php::Var php_coalescemixedarrayaccess(');

        self::assertStringContainsString('.isObject()', $body);
        self::assertStringContainsString('php::exists(', $body);
        self::assertStringContainsString('.offsetSet(key,', $body);
        self::assertStringContainsString('.assignKeyedDimension(key,', $body);
    }

    public function testMagicContainerIsEvaluatedOncePerReadAndWritePhase(): void
    {
        $code = $this->compileFixture();
        $body = $this->extractFunctionBody($code, 'php::Var php_coalescemagicarrayaccess(');

        self::assertSame(2, substr_count($body, 'typephp_read_property_cached(holder,'));
        self::assertStringContainsString('[&](auto &&', $body);
    }

    public function testFixedArrayKeepsDirectFastPathWhenItsTypeCannotChange(): void
    {
        $code = $this->compileFixture();
        $body = $this->extractFunctionBody($code, 'php::Var php_coalescefixedarray(');

        self::assertStringContainsString('.item(key, true)', $body);
        self::assertStringNotContainsString('.isObject()', $body);
        self::assertStringNotContainsString('.isArray()', $body);
    }

    public function testFixedArrayUsesRuntimeDispatchWhenRhsCanReplaceIt(): void
    {
        $code = $this->compileFixture();
        $body = $this->extractFunctionBody($code, 'php::Var php_coalescemutablefixedarray(');

        self::assertStringContainsString('.isObject()', $body);
        self::assertStringContainsString('.offsetSet(key,', $body);
        self::assertStringContainsString('.assignKeyedDimension(key,', $body);
    }

    private function extractFunctionBody(string $code, string $signature): string
    {
        $start = strpos($code, $signature);
        self::assertIsInt($start, "missing function: {$signature}");
        $end = strpos($code, "\n}", $start);
        self::assertIsInt($end);
        return substr($code, $start, $end - $start);
    }

    private function compileFixture(): string
    {
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/arrayaccess-coalesce-assign-codegen.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        return $code;
    }
}

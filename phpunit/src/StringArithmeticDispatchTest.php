<?php

use TypePhp\CompilerTest;

final class StringArithmeticDispatchTest extends \BaseTest
{
    public function testStringResultsStayDynamicAndNativeScalarsStayUnboxed(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/string-arithmetic-dispatch.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        self::assertIsString($code);
        self::assertStringContainsString('php::Var result;', $code);
        self::assertStringContainsString('((php::Var(value)) * (php::Var(factor)))', $code);
        self::assertMatchesRegularExpression('/php::Var\(get_str\(\d+\)\)\) \/ \(php::Var\(3L+\)\)/', $code);
        self::assertStringContainsString('php::Int php_nativeboolsum(php::Bool left, php::Bool right)', $code);
        self::assertStringContainsString('((left) + (right))', $code);
        self::assertStringContainsString('((php::toBool(left)) == (php::toBool(right)))', $code);
    }
}

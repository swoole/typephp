<?php

use TypePhp\CompilerTest;

final class DynamicArithmeticResultTest extends \BaseTest
{
    public function testDynamicResultRemainsBoxedAndNativeArithmeticStaysNative(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/dynamic-arithmetic-result.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        self::assertIsString($code);
        $dynamic = substr($code, strpos($code, 'php_dynamicresult('));
        $dynamic = substr($dynamic, 0, strpos($dynamic, "\n}") + 3);
        self::assertDoesNotMatchRegularExpression('/php::toInt\([^;]*divisor/s', $dynamic);
        self::assertMatchesRegularExpression('/php::Int php_nativeintresult\(php::Int left, php::Int right\)/', $code);
        self::assertMatchesRegularExpression('/php::Float php_nativefloatresult\(php::Float left, php::Float right\)/', $code);
        foreach (['php_nativeintresult(', 'php_nativefloatresult('] as $name) {
            $body = substr($code, strpos($code, $name));
            $body = substr($body, 0, strpos($body, "\n}") + 3);
            self::assertStringContainsString('((left) / (right))', $body);
            self::assertStringNotContainsString('php::Var', $body);
        }
        self::assertMatchesRegularExpression('/php::Var (tmp_var_\d+);.*?\1 = \(+tmp_var_\d+\) \/ \(divisor\)/s', $dynamic);
    }
}

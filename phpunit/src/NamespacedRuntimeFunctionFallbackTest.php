<?php

use TypePhp\CompilerTest;

final class NamespacedRuntimeFunctionFallbackTest extends BaseTest
{
    public function testUnknownNamespaceSignatureNeverUsesGlobalArgumentOrReturnContracts(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/namespaced-runtime-function-fallback.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertIsString($code);
        preg_match('/php::Var php_runtimefunctionfallback__length\([^\n]*\) \{(.*?)\n\}/s', $code, $length);
        self::assertCount(2, $length);
        self::assertStringContainsString('php::fn::function_exists(', $length[1]);
        self::assertStringContainsString('php::call(', $length[1]);
        self::assertStringNotContainsString('php::fn::strlen(', $length[1]);
        self::assertStringNotContainsString('throwReturnTypeError', $length[1]);
        preg_match('/php::Var php_runtimefunctionfallback__globalnativefallback\([^\n]*\) \{(.*?)\n\}/s', $code, $fallback);
        self::assertCount(2, $fallback);
        self::assertStringContainsString('php::fn::function_exists(', $fallback[1]);
        self::assertStringNotContainsString('php_globalfallback(', $fallback[1]);
        preg_match('/php::Int php_runtimefunctionfallback__explicitglobal\([^\n]*\) \{(.*?)\n\}/s', $code, $global);
        self::assertCount(2, $global);
        self::assertSame(2, substr_count($global[1], 'php::fn::strlen('));
        self::assertStringNotContainsString('get_function_resolution_cache(', $global[1]);
        self::assertStringContainsString('php_runtimefunctionfallback__firstclass(', $code);
    }
}

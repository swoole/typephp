<?php

use TypePhp\CompilerTest;

final class FunctionImportResolutionTest extends BaseTest
{
    public function testImportedFunctionsTakePrecedenceRegardlessOfAliasCase(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/function-import-resolution.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        preg_match('/void php_aliasconsumer__exercise\(\) \{(.*?)\n\}/s', $code, $match);
        self::assertCount(2, $match);
        self::assertSame(5, substr_count($match[1], 'php_aliaslibrary__route('));
        self::assertSame(1, substr_count($match[1], 'php_route('));
        // The three first-class callables are materialized through
        // Closure::fromCallable(); ordinary aliases above must stay direct.
        self::assertSame(3, substr_count($match[1], 'php::call('));
        self::assertStringNotContainsString('ZEND_STRL("sIzE")', $match[1]);
        self::assertStringNotContainsString('ZEND_STRL("cAlLbAcK_tArGeT")', $match[1]);

        $literalStrings = (new \ReflectionProperty($compiler, 'literalStrings'))->getValue($compiler);
        self::assertArrayHasKey('strlen', $literalStrings);
        self::assertArrayHasKey('AliasLibrary\\callback_target', $literalStrings);
        self::assertArrayNotHasKey('sIzE', $literalStrings);
        self::assertArrayNotHasKey('cAlLbAcK_tArGeT', $literalStrings);
    }
}

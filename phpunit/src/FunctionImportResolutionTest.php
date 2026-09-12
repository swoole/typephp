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
        self::assertStringNotContainsString('php::call(', $match[1]);
    }
}

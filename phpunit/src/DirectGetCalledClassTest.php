<?php

use TypePhp\CompilerTest;

final class DirectGetCalledClassTest extends BaseTest
{
    public function testBuiltinUsesRuntimeCalledClassInInstanceAndStaticMethods(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/direct-get-called-class.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertSame(2, substr_count($code, 'const php::Str _typephp_called_class ='));
        self::assertStringNotContainsString('php::call(', $code);
        self::assertStringContainsString('php_directcalledbase__instancename(object)', $code);
    }

    public function testCaseInsensitiveUserFunctionAliasIsNotReplacedWithClassName(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/get-called-class-shadow-alias.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertStringNotContainsString('_typephp_called_class', $code);
    }
}

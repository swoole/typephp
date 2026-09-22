<?php

use TypePhp\CompilerTest;

final class UnaryPlusCodegenTest extends \BaseTest
{
    public function testNativeNumericUnaryPlusDoesNotBoxItsOperand(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/unary-plus-codegen.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        self::assertStringNotContainsString('php::Variant(number)', $code);
        self::assertStringContainsString('php::toInt(number)', $code);
        self::assertStringContainsString('php::toFloat(number)', $code);
    }
}

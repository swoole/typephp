<?php

use TypePhp\CompilerTest;

final class FloatNumericStringArithmeticTest extends \BaseTest
{
    public function testFloatNumericStringOperandsKeepFloatSemantics(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/float-numeric-string-arithmetic.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        self::assertIsString($code);

        // "1e2" must convert to a C++ double literal, not an integer literal,
        // so the division stays in the floating-point domain.
        self::assertStringContainsString('((1e+2) / (4L))', $code);
        self::assertDoesNotMatchRegularExpression('/\(\(100\) \/ \(4L\)\)/', $code);

        // The inferred storage of an assignment from float-valued numeric
        // string arithmetic is php::Float, not php::Int.
        self::assertMatchesRegularExpression('/php::Float x = /', $code);
        self::assertMatchesRegularExpression('/php::Float y = /', $code);
        self::assertMatchesRegularExpression('/php::Float php_floatnumericstringquotient\(\)/', $code);

        // Integer-syntax numeric strings keep native integer arithmetic.
        self::assertStringContainsString('((10) / (2L))', $code);
    }
}

<?php

class UniversalMethodCallTest extends \BaseTest
{
    public function testUnknownMethodOnInt()
    {
        $this->exec("Cannot call method `unknownMethod()` on variable of type php::Int", 'universal-method-int-undefined.php');
    }

    public function testAddWithWrongArgCount()
    {
        $this->exec('Method `add()` expects exactly 1 argument(s), 0 given', 'universal-method-int-wrong-args.php');
    }

    public function testMutatingMethodOnNonVarExpr()
    {
        $this->exec('Cannot call mutating method `push()` on a non-variable expression', 'universal-method-mutating-expr.php');
    }

    public function testVoidMethodCall()
    {
        $this->compile('void-method-call.php');
    }

    public function testHighPrecisionToBoolUsesNumericConversion(): void
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/universal-method-high-precision-to-bool.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        self::assertStringContainsString('php::BigInt::toBool(', $code);
        self::assertStringContainsString('php::BigFloat::toBool(', $code);
        self::assertStringContainsString('php::Decimal::toBool(', $code);
        self::assertStringContainsString('php::toBigFloat(php::toInt(1L))', $code);
    }

    public function testArgumentUnpackingIsRejected(): void
    {
        $this->exec('Method `add()` does not support argument unpacking', 'universal-method-arg-unpack.php');
    }

    public function testNamedArgumentsAreRejectedForNativeMethods(): void
    {
        $this->exec('Method `add()` does not support named arguments', 'universal-method-arg-named-native.php');
    }

    public function testNamedArgumentsMapToPhpFunctionParameters(): void
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/universal-method-named-args.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));

        self::assertIsString($code);
        // The skipped pad_string is filled with its default and pad_type follows it.
        self::assertMatchesRegularExpression('/ArgList\{s, 6L, [^}]*0L\}/', $code);
    }

    public function testUnknownNamedArgumentIsRejected(): void
    {
        $this->exec('Unknown named argument `nope` for method `pad()`', 'universal-method-named-unknown.php');
    }

    public function testNamedArgumentCannotTargetReceiver(): void
    {
        $this->exec('Named argument `string` targets the receiver', 'universal-method-named-receiver.php');
    }

    public function testPositionalArgumentAfterNamedIsRejected(): void
    {
        $this->exec('Cannot use positional argument after named argument', 'universal-method-named-positional-after.php');
    }

    public function testNamedArgumentCannotOverwritePositional(): void
    {
        $this->exec('Named argument `length` overwrites an earlier argument', 'universal-method-named-overwrite.php');
    }

    public function testKeywordExtensionCannotUseBuiltinKeywordName(): void
    {
        $this->exec(
            'Keyword extension method *::TOINT() conflicts with the builtin keyword method toInt()',
            'universal-method-keyword-extension-builtin-name.php',
        );
    }

    public function testCustomKeywordMethodCannotChangeReturnType(): void
    {
        $this->exec('Conversion method `BadToInt::toInt()` must return exactly `int`', 'keyword-method-custom-bad-return.php');
    }

    public function testCustomKeywordMethodCannotAcceptParameters(): void
    {
        $this->exec('Conversion method `ParamToString::toString()` must not accept arguments', 'keyword-method-custom-params.php');
    }

    public function testCustomKeywordMethodCannotBeStatic(): void
    {
        $this->exec('Keyword method `StaticToBool::toBool()` must not be static', 'keyword-method-custom-static.php');
    }

    public function testCustomKeywordMethodCannotBeNullable(): void
    {
        $this->exec('Conversion method `NullableToFloat::toFloat()` must return exactly `float`', 'keyword-method-custom-nullable.php');
    }

    public function testInterfaceKeywordMethodCannotChangeReturnType(): void
    {
        $this->exec('Conversion method `Convertible::toInt()` must return exactly `int`', 'keyword-method-custom-interface.php');
    }
}

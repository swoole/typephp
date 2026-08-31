<?php

class OperatorTest extends \BaseTest
{
    public function testBooleanLiteralStrictComparisonUsesNativeBoolOperands(): void
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/bool-literal-identical.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $cppFile = $compiler->convertFile($testFile);
        $cpp = file_get_contents($cppFile);

        $this->assertStringContainsString('true == pjax', $cpp);
        $this->assertStringContainsString('pjax == true', $cpp);
        $this->assertStringContainsString('false == pjax', $cpp);
        $this->assertStringContainsString('pjax == false', $cpp);
        $this->assertStringNotContainsString('php::true_ == pjax', $cpp);
        $this->assertStringNotContainsString('php::false_ == pjax', $cpp);
        $this->assertStringContainsString('php::same(php::true_, value)', $cpp);
    }

    public function testAssignedValueNotIdenticalToNullIsParenthesized(): void
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/assign-not-identical-null.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $cppFile = $compiler->convertFile($testFile);
        $cpp = file_get_contents($cppFile);

        $this->assertMatchesRegularExpression(
            '/!\(php::toBool\(\(error = php::call\([^\n]+\)\)\.isNull\(\)\)\)/',
            $cpp,
        );
    }

    public function testDynamicBoolCallInLogicalExpressionIsConvertedToNativeBool(): void
    {
        global $translator;
        $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/bool-dynamic-call-logical.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $cppFile = $compiler->convertFile($testFile);
        $cpp = file_get_contents($cppFile);

        // Ordered operands may be materialized before the return statement,
        // but the dynamic call result must still cross an explicit Bool
        // conversion boundary before C++ logical operators consume it.
        $this->assertStringContainsString('php::toBool(php::call(', $cpp);
    }

    public function testLiteralIntDivideByZeroDoesNotCompile(): void
    {
        $this->exec('Cannot divide or modulo by zero', 'divide-by-zero-int.php');
    }

    public function testLiteralFloatDivideByZeroDoesNotCompile(): void
    {
        $this->exec('Cannot divide or modulo by zero', 'divide-by-zero-float.php');
    }

    public function testLiteralStringDivideByZeroDoesNotCompile(): void
    {
        $this->exec('Cannot divide or modulo by zero', 'divide-by-zero-string.php');
    }

    public function testLiteralModuloByZeroDoesNotCompile(): void
    {
        $this->exec('Cannot divide or modulo by zero', 'modulo-by-zero-int.php');
    }

    public function testLiteralDivideAssignByZeroDoesNotCompile(): void
    {
        $this->exec('Cannot divide or modulo by zero', 'assign-divide-by-zero.php');
    }

    public function testLiteralModuloAssignByZeroDoesNotCompile(): void
    {
        $this->exec('Cannot divide or modulo by zero', 'assign-modulo-by-zero.php');
    }

    public function testFloatLiteralSpecialValuesAndWholeNumbers(): void
    {
        $previous = ini_set('precision', '14');
        try {
            global $translator;
            $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
            $translator = $compiler;
            $testFile = __DIR__ . '/../code/float-literal-special.php';
            $compiler->addFiles([$testFile]);
            $compiler->prepareFile($testFile);
            $cppFile = $compiler->convertFile($testFile);
            $cpp = file_get_contents($cppFile);
        } finally {
            if ($previous !== false) {
                ini_set('precision', $previous);
            }
        }

        $this->assertStringContainsString('1.0', $cpp);
        $this->assertStringContainsString('0.0', $cpp);
        $this->assertStringContainsString('std::numeric_limits<double>::infinity()', $cpp);
        // Unary minus always parenthesizes its operand (see parseUnaryMinus).
        $this->assertStringContainsString('-(std::numeric_limits<double>::infinity())', $cpp);
        $this->assertStringContainsString('std::numeric_limits<double>::quiet_NaN()', $cpp);
        $this->assertStringContainsString('2.7182818284590451', $cpp);
        $this->assertStringNotContainsString('2.718281828459)', $cpp);
    }

    public function testFloatDeclarationMetadataIgnoresHostPrecisionAndHandlesSpecialValues(): void
    {
        $previous = ini_set('precision', '14');
        try {
            global $translator;
            $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
            $translator = $compiler;
            $testFile = TYPEPHP_ROOT_PATH . '/phpunit/code/float-declaration-metadata.php';
            $compiler->addFiles([$testFile]);
            $compiler->prepareFile($testFile);
            $compiler->convertFile($testFile);
            $arginfoHeader = $compiler->getArgInfoHeaderFile($testFile);
            $arginfo = file_get_contents($arginfoHeader);
            $extension = file_get_contents($compiler->genExtension());
        } finally {
            if ($previous !== false) {
                ini_set('precision', $previous);
            }
        }

        $this->assertStringContainsString('ZVAL_DOUBLE(&const_POSITIVE_INF_value, std::numeric_limits<double>::infinity());', $arginfo);
        $this->assertStringContainsString('ZVAL_DOUBLE(&const_NEGATIVE_INF_value, -std::numeric_limits<double>::infinity());', $arginfo);
        $this->assertStringContainsString('ZVAL_DOUBLE(&const_NOT_A_NUMBER_value, std::numeric_limits<double>::quiet_NaN());', $arginfo);
        $this->assertStringContainsString('ZVAL_DOUBLE(&const_CONST_E_value, 2.7182818284590451);', $arginfo);
        $this->assertStringContainsString('ZVAL_DOUBLE(&const_CONST_ONE_POINT_FIVE_value, 1.5);', $arginfo);
        $this->assertStringNotContainsString('2.718281828459);', $arginfo);

        $this->assertStringContainsString('php::toFloat(2.7182818284590451)', $extension);
        $this->assertStringContainsString('php::toFloat(std::numeric_limits<double>::infinity())', $extension);
        $this->assertStringContainsString('php::toFloat(std::numeric_limits<double>::quiet_NaN())', $extension);
        $this->assertStringNotContainsString('2.718281828459)', $extension);
    }

    public function testFloatLiteralEmissionIsLocaleIndependent(): void
    {
        $previousLocale = setlocale(LC_NUMERIC, 0);
        try {
            $selectedLocale = setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'da_DK.UTF-8', 'en_DK.utf8');
            if ($selectedLocale === false || localeconv()['decimal_point'] !== ',') {
                $this->markTestSkipped('No comma-decimal locale is available');
            }

            global $translator;
            $compiler = \TypePhp\CompilerTest::create(TYPEPHP_ROOT_PATH);
            $translator = $compiler;
            $result = $compiler->genFloatLiteral(1.5);
            $this->assertSame('1.5', $result);
            $this->assertStringNotContainsString(',', $result);
        } finally {
            if ($previousLocale !== false) {
                setlocale(LC_NUMERIC, $previousLocale);
            }
        }
    }
}

<?php

use TypePhp\CompilerTest;

final class LocalVariableInitializerTest extends \BaseTest
{
    public function testTopLevelLiteralAssignmentsInitializeDeclarations(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/local-literal-declaration-initializer.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        self::assertStringContainsString('php::Int integer = php::toInt(42L);', $code);
        self::assertStringContainsString('php::Int negative = php::toInt(-7L);', $code);
        self::assertStringContainsString('php::Float floating = php::toFloat(1.25);', $code);
        self::assertStringContainsString('php::Bool boolean = php::toBool(true);', $code);
        self::assertMatchesRegularExpression('/php::Str string = get_str\(\d+\);/', $code);
        self::assertStringContainsString('php::Var nullValue = php::null;', $code);
        self::assertStringContainsString('php::Var explicitAny;', $code);
        self::assertStringContainsString('explicitAny = 100L;', $code);

        self::assertStringContainsString('php::Int nested = 0;', $code);
        self::assertStringContainsString('nested = php::toInt(9L);', $code);
        self::assertStringContainsString('php::Int computed = 0;', $code);
        self::assertStringContainsString('computed = php::toInt(((40L) + (2L)));', $code);

        $afterDeclaration = substr(
            $code,
            strpos($code, 'php::Int integer = php::toInt(42L);')
                + strlen('php::Int integer = php::toInt(42L);'),
        );
        self::assertStringNotContainsString('integer = php::toInt(42L);', $afterDeclaration);
    }

    public function testVarIntModeBoxesOnlyIntegerLiterals(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/local-literal-declaration-initializer-varint.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        self::assertStringContainsString('php::Var integer = 42L;', $code);
        self::assertStringContainsString('php::Var negative = -7L;', $code);
        self::assertStringContainsString('php::Float floating = php::toFloat(1.25);', $code);
        self::assertStringContainsString('php::Bool boolean = php::toBool(true);', $code);
        self::assertMatchesRegularExpression('/php::Str string = get_str\(\d+\);/', $code);
        self::assertStringContainsString('php::Var nullValue = php::null;', $code);
    }

    public function testOnlyCompileTimeConstantsInitializeHoistedDeclarations(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/local-constant-declaration-initializer.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        self::assertStringContainsString(
            'php::Int imported = php::toInt(_const_var_LocalConstantInitializer__Provider__LIMIT);',
            $code,
        );
        self::assertStringContainsString(
            'php::Str qualified = _const_var_LocalConstantInitializer__Provider__LABEL;',
            $code,
        );
        self::assertStringContainsString(
            'php::Bool namespaced = php::toBool(_const_var_LocalConstantInitializer__Consumer__ENABLED);',
            $code,
        );
        self::assertStringContainsString('php::Int internal = php::toInt(ZEND_LONG_MAX);', $code);

        self::assertStringContainsString('php::Var runtime;', $code);
        self::assertStringContainsString('runtime = php::constant(', $code);
        self::assertStringContainsString('php::Var namespaceFallback;', $code);
        self::assertStringContainsString('namespaceFallback = php::constant(', $code);
    }

    public function testOnlyCompileTimeClassConstantsInitializeHoistedDeclarations(): void
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/local-class-constant-declaration-initializer.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        self::assertStringContainsString('php::Str selfValue = get_str(', $code);
        self::assertStringContainsString('php::Int parentValue = php::toInt(128L);', $code);
        self::assertStringContainsString('php::Str concreteValue = get_str(', $code);
        self::assertStringContainsString('php::Str selfClass = get_str(', $code);
        self::assertStringContainsString('php::Str parentClass = get_str(', $code);
        self::assertStringContainsString('php::Str unknownClass = get_str(', $code);

        self::assertStringContainsString('php::Var lateStatic;', $code);
        self::assertStringContainsString(
            'zend_class_entry *const _typephp_called_ce = typephp_get_called_ce(this_);',
            $code,
        );
        self::assertStringContainsString('lateStatic = php::constant(_typephp_called_ce', $code);
        self::assertStringContainsString('php::Var external = get_str(', $code);
        self::assertStringNotContainsString("php::Var external;\n", $code);
        self::assertStringContainsString('php::Var runtimeClassConstant;', $code);
        self::assertStringContainsString('runtimeClassConstant = php::constant(', $code);
        self::assertStringContainsString('php::Var dynamicClass;', $code);
        self::assertStringContainsString('dynamicClass = php::classConstant(', $code);
    }
}

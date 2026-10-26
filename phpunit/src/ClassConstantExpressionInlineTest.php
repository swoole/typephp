<?php

use TypePhp\CompilerTest;

final class ClassConstantExpressionInlineTest extends \BaseTest
{
    private ?CompilerTest $compiler = null;

    public function testLeafConstantIsInlinedButExpressionConstantUsesZendLookup(): void
    {
        $code = $this->compileFixture();

        $leaf = $this->methodBody($code, 'php_classconstantexpressioninline__readleaf');
        self::assertDoesNotMatchRegularExpression('/php::(constant|classConstant)\(/', $leaf);
        self::assertMatchesRegularExpression('/= \(1L+\);/', $leaf);

        $mask = $this->methodBody($code, 'php_classconstantexpressioninline__readmask');
        self::assertStringContainsString('php::constant(', $mask);
        self::assertDoesNotMatchRegularExpression('/php::Var\(tmp_var_\d+\)/', $mask);
    }

    public function testInterfaceConstantFetchOnImplementingClassUsesZendLookup(): void
    {
        $code = $this->compileFixture();

        $combined = $this->methodBody(
            $code,
            'php_classconstantexpressioninlineflaguser__readcombined'
        );
        self::assertStringContainsString('php::constant(', $combined);
        self::assertDoesNotMatchRegularExpression('/php::Var\(tmp_var_\d+\)/', $combined);
    }

    public function testInterfaceConstantDefaultParameterEvaluatesAtCompileTime(): void
    {
        $this->compileFixture();

        // The default-parameter (arginfo) path resolves the constant through
        // getClassConstValue, which must stay independent of the method-body
        // inline-eligibility gate: an expression-valued interface constant
        // fetched on the implementing class must still evaluate to 3.
        $default = $this->compiler->getClassConstValue(
            new \PhpParser\Node\Expr\ClassConstFetch(
                new \PhpParser\Node\Name('ClassConstantExpressionInlineFlagUser'),
                'COMBINED',
            ),
            'ClassConstantExpressionInlineFlagUser',
            'COMBINED',
        );

        self::assertSame(3, $default);
    }

    public function testNativeLiteralContainingTmpVarNameIsInlinedWithNoLiteralStrings(): void
    {
        $code = $this->compileFixture(['noLiteralStrings' => true]);

        // Native classes emit their methods as C++ member functions, so the
        // body selector uses the qualified `Class::method` name.
        $label = $this->methodBody(
            $code,
            'php_classconstantexpressioninlinenativemarker::label'
        );
        self::assertStringContainsString('ZEND_STRL("tmp_var_label")', $label);
        self::assertDoesNotMatchRegularExpression('/php::(constant|classConstant)\(/', $label);
    }

    /**
     * @param array{noLiteralStrings?: bool} $options
     */
    private function compileFixture(array $options = []): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        if (isset($options['noLiteralStrings'])) {
            $property = new \ReflectionProperty(\TypePhp\CompilerBase::class, 'noLiteralStrings');
            $property->setValue($compiler, $options['noLiteralStrings']);
        }
        $this->compiler = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/class-constant-expression-inline.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        return $code;
    }

    private function methodBody(string $code, string $function): string
    {
        $pattern = '/' . preg_quote($function, '/') . '\([^)]*\) \{[\s\S]*?\n\}/';
        preg_match($pattern, $code, $matches);

        self::assertSame(1, count($matches), "Missing body for {$function}");
        return $matches[0];
    }
}

<?php

use TypePhp\CompilerTest;

final class ClassConstantExpressionInlineTest extends \BaseTest
{
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

    private function compileFixture(): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
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

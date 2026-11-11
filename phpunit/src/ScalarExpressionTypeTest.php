<?php

final class ScalarExpressionTypeTest extends BaseTest
{
    public function testFixedExpressionResultsUseNativeStorage(): void
    {
        $code = $this->generatedCode();
        foreach ([
            'scalarstringcast' => ['Str', 'text'],
            'scalarinterpolated' => ['Str', 'text'],
            'scalarinstancecheck' => ['Bool', 'matches'],
            'scalarissetcheck' => ['Bool', 'present'],
            'scalaremptycheck' => ['Bool', 'empty'],
        ] as $name => [$type, $variable]) {
            self::assertSame(1, preg_match('/php::\w+ php_' . $name . '\(.*?\) \{(.*?)\n\}/s', $code, $matches));
            self::assertStringContainsString('php::' . $type . ' ' . $variable, $matches[1]);
            self::assertStringNotContainsString('throwReturnTypeError(', $matches[1]);
        }
    }

    public function testBuiltinUnionReturnsRetainTheirRuntimeTypes(): void
    {
        $code = $this->generatedCode();
        self::assertSame(1, preg_match('/php::Array php_scalarunionbuiltins\(.*?\) \{(.*?)\n\}/s', $code, $matches));
        foreach (['found', 'last', 'sum', 'power', 'decoded'] as $variable) {
            self::assertStringContainsString('php::Var ' . $variable . ';', $matches[1]);
        }
        self::assertStringContainsString('php::Int length', $code);
        self::assertStringContainsString('php::Str part', $code);
    }

    private function generatedCode(): string
    {
        global $translator;
        $this->compile('scalar-expression-types.php');
        $file = TYPEPHP_ROOT_PATH . '/phpunit/code/scalar-expression-types.php';
        return file_get_contents($translator->getCppFile($file));
    }
}

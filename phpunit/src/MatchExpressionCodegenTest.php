<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

use TypePhp\CompilerTest;

/**
 * @internal
 * @coversNothing
 */
final class MatchExpressionCodegenTest extends \BaseTest
{
    private string $generatedCode;

    protected function setUp(): void
    {
        parent::setUp();

        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/match-expression-codegen.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        self::assertIsString($code);
        $this->generatedCode = $code;
    }

    public function testUniformIntMatchInfersIntAndAvoidsRuntimeReturnTypeCheck(): void
    {
        // Must emit typed lambda returning php::Int
        self::assertStringContainsString('php_testuniformintmatch', $this->generatedCode);
        self::assertStringContainsString('[&]() -> php::Int {', $this->generatedCode);

        // Function body must not use php::Var temporary or throwReturnTypeError for uniform int match
        $fnCode = $this->extractFunction('php_testuniformintmatch');
        self::assertStringNotContainsString('php::Var', $fnCode);
        self::assertStringNotContainsString('php::throwReturnTypeError', $fnCode);
        self::assertMatchesRegularExpression('/return 10L{1,2};/', $fnCode);
        self::assertMatchesRegularExpression('/return 20L{1,2};/', $fnCode);
        self::assertMatchesRegularExpression('/return 30L{1,2};/', $fnCode);
    }

    public function testUniformStringMatchInfersString(): void
    {
        self::assertStringContainsString('php_testuniformstringmatch', $this->generatedCode);
        $fnCode = $this->extractFunction('php_testuniformstringmatch');
        self::assertStringContainsString('[&]() -> php::Str {', $fnCode);
        self::assertStringNotContainsString('php::Var', $fnCode);
        self::assertStringNotContainsString('php::throwReturnTypeError', $fnCode);
    }

    public function testMatchWithThrowArmPreservesInferredReturnType(): void
    {
        $fnCode = $this->extractFunction('php_testmatchwiththrow');
        self::assertStringContainsString('[&]() -> php::Int {', $fnCode);
        self::assertStringContainsString('php::throwException(', $fnCode);
        self::assertStringContainsString('return {};', $fnCode);
    }

    public function testMatchNoDefaultEmitsTypedUnhandledMatchCase(): void
    {
        $fnCode = $this->extractFunction('php_testmatchnodefault');
        self::assertStringContainsString('[&]() -> php::Int {', $fnCode);
        self::assertStringContainsString('php::throwException("UnhandledMatchError", "Unhandled match case");', $fnCode);
        self::assertStringContainsString('return {};', $fnCode);
    }

    public function testMixedTypeMatchFallsBackToVariant(): void
    {
        $fnCode = $this->extractFunction('php_testmixedtypematch');
        self::assertStringContainsString('[&]() -> php::Var {', $fnCode);
        self::assertStringContainsString('php::Var tmp_var_', $fnCode);
    }

    public function testMatchExpressionLocalVariableAssignment(): void
    {
        $fnCode = $this->extractFunction('php_testmatchlocalvariableassignment');
        // $result must be inferred as php::Int rather than php::Var
        self::assertStringContainsString('php::Int result = 0;', $fnCode);
        self::assertStringNotContainsString('php::Var result;', $fnCode);
    }

    private function extractFunction(string $name): string
    {
        $pos = strpos($this->generatedCode, $name);
        self::assertNotFalse($pos, "Function {$name} not found in generated code");
        $braceStart = strpos($this->generatedCode, '{', $pos);
        self::assertNotFalse($braceStart);

        $depth = 0;
        $len = strlen($this->generatedCode);
        for ($i = $braceStart; $i < $len; $i++) {
            $char = $this->generatedCode[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($this->generatedCode, $pos, $i - $pos + 1);
                }
            }
        }

        return substr($this->generatedCode, $pos, 1000);
    }
}

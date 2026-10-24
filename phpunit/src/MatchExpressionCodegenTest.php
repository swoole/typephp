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

    public function testMatchArmPropertyReadConvertsAtTypedBoundary(): void
    {
        $fnCode = $this->extractFunction('php_testmatcharmpropertyread');
        self::assertStringContainsString('[&]() -> php::Int {', $fnCode);
        self::assertStringContainsString('php::toInt(obj.attr(', $fnCode);

        $ternaryCode = $this->extractFunction('php_testmatcharmternarypropertyread');
        self::assertStringContainsString('[&]() -> php::Int {', $ternaryCode);
        self::assertStringContainsString('php::toInt(', $ternaryCode);
    }

    public function testMatchHighPrecisionBoxedPreservesVariantRepresentation(): void
    {
        $bigIntFn = $this->extractFunction('php_testmatchbigint');
        self::assertStringContainsString('[&]() -> php::Var {', $bigIntFn);
        self::assertStringContainsString('php::Var value;', $bigIntFn);
        self::assertStringNotContainsString('-> php::BigInt', $bigIntFn);
        self::assertStringNotContainsString('php::BigInt value', $bigIntFn);

        $decimalFn = $this->extractFunction('php_testmatchdecimal');
        self::assertStringContainsString('[&]() -> php::Var {', $decimalFn);
        self::assertStringContainsString('php::Var value;', $decimalFn);
        self::assertStringNotContainsString('-> php::Decimal', $decimalFn);
        self::assertStringNotContainsString('php::Decimal value', $decimalFn);
    }

    public function testVarIntMatchDynamicArithmeticRetainsVariantAndChecksReturnType(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/match-expression-varint-codegen.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $code = file_get_contents($compiler->convertFile($source));
        self::assertIsString($code);

        // Mixed return type function: lambda returns php::Var
        $mixedFn = $this->extractFunctionFromCode($code, 'php_testvarintmatchmixed');
        self::assertStringContainsString('[&]() -> php::Var {', $mixedFn);
        self::assertStringContainsString('return ((php::Var(y)) + (1L', $mixedFn);

        // Int return type function: lambda returns php::Var and emits runtime return type error check
        $intFn = $this->extractFunctionFromCode($code, 'php_testvarintmatchint');
        self::assertStringContainsString('[&]() -> php::Var {', $intFn);
        self::assertStringContainsString('php::throwReturnTypeError(', $intFn);
        self::assertStringContainsString('return php::toInt(', $intFn);
    }

    public function testGeneratedCppCompilesCleanlyWithClang(): void
    {
        $cxx = $this->findCxxCompiler();
        if ($cxx === null) {
            $this->markTestSkipped('C++ compiler (clang++ or g++) not available in environment');
        }

        $includeDirs = $this->resolveIncludeFlags();
        if ($includeDirs === null) {
            $this->markTestSkipped('PHP include headers not available');
        }

        // Test compilation of main match-expression-codegen.cc
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $compiler->setBuildMode(CompilerTest::BUILD_MODE_EXT);
        $compiler->setTargetName('test_match_codegen');
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/match-expression-codegen.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $compiler->convert([$source]);
        $ccFile = $compiler->convertFile($source);

        $cmd = escapeshellarg($cxx) . ' -fsyntax-only -std=c++20 ' . $includeDirs . ' ' . escapeshellarg($ccFile) . ' 2>&1';
        exec($cmd, $output, $exitCode);
        self::assertSame(0, $exitCode, "C++ compilation failed:\n" . implode("\n", $output));

        // Test compilation of match-expression-varint-codegen.cc
        $varintCompiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $varintCompiler->setBuildMode(CompilerTest::BUILD_MODE_EXT);
        $varintCompiler->setTargetName('test_match_varint');
        $varintSource = TYPEPHP_ROOT_PATH . '/phpunit/code/match-expression-varint-codegen.php';
        $varintCompiler->addFiles([$varintSource]);
        $varintCompiler->prepareFile($varintSource);
        $varintCompiler->convert([$varintSource]);
        $varintCcFile = $varintCompiler->convertFile($varintSource);

        $varintCmd = escapeshellarg($cxx) . ' -fsyntax-only -std=c++20 ' . $includeDirs . ' ' . escapeshellarg($varintCcFile) . ' 2>&1';
        exec($varintCmd, $varintOutput, $varintExitCode);
        self::assertSame(0, $varintExitCode, "Varint C++ compilation failed:\n" . implode("\n", $varintOutput));
    }

    private function findCxxCompiler(): ?string
    {
        foreach (['clang++', 'g++', 'c++'] as $candidate) {
            $path = shell_exec('command -v ' . escapeshellarg($candidate));
            if ($path !== null && trim($path) !== '') {
                return trim($path);
            }
        }
        return null;
    }

    private function resolveIncludeFlags(): ?string
    {
        $phpConfig = shell_exec('command -v php-config');
        if ($phpConfig === null || trim($phpConfig) === '') {
            return null;
        }
        $phpIncludes = trim((string) shell_exec('php-config --includes'));
        $flags = '-I build/include -I vendor/swoole/phpx/include ' .
            '-I vendor/swoole/phpx/thirdparty/mpdecimal/libmpdec++ ' .
            '-I vendor/swoole/phpx/thirdparty/mpdecimal/libmpdec ' .
            $phpIncludes;

        foreach (['gmp', 'mpfr', 'mpdecimal'] as $pkg) {
            $prefix = trim((string) @shell_exec('brew --prefix ' . escapeshellarg($pkg) . ' 2>/dev/null'));
            if ($prefix !== '' && is_dir($prefix . '/include')) {
                $flags .= ' -I ' . escapeshellarg($prefix . '/include');
            }
        }

        return $flags;
    }

    private function extractFunction(string $name): string
    {
        return $this->extractFunctionFromCode($this->generatedCode, $name);
    }

    private function extractFunctionFromCode(string $code, string $name): string
    {
        $pos = strpos($code, $name);
        self::assertNotFalse($pos, "Function {$name} not found in generated code");
        $braceStart = strpos($code, '{', $pos);
        self::assertNotFalse($braceStart);

        $depth = 0;
        $len = strlen($code);
        for ($i = $braceStart; $i < $len; $i++) {
            $char = $code[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $pos, $i - $pos + 1);
                }
            }
        }

        return substr($code, $pos, 1000);
    }
}

<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

/**
 * @internal
 * @coversNothing
 */
final class VarIntTypesTest extends BaseTest
{
    public function testRemovedNativeTypesDirectiveHasStableMigrationDiagnostic(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage(
            '`use native_types` has been removed; native scalar types are now the default',
        );

        $compiler = $this->createCompiler();
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/removed-native-types.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
    }

    public function testVarIntTypesDirectiveIsCaseInsensitiveAndOnlyBoxesIntegers(): void
    {
        $code = $this->compileSource(
            $this->createCompiler(),
            TYPEPHP_ROOT_PATH . '/phpunit/code/varint-types-case-insensitive.php',
        );

        self::assertStringContainsString('php::Var integer = 42L;', $code);
        self::assertStringContainsString('php::Float floating = php::toFloat(1.5);', $code);
        self::assertStringContainsString('php::Bool boolean = php::toBool(true);', $code);
    }

    public function testVarIntTypesModeDoesNotLeakIntoTheNextFile(): void
    {
        $compiler = $this->createCompiler();
        $varIntSource = TYPEPHP_ROOT_PATH . '/phpunit/code/local-literal-declaration-initializer-varint.php';
        $defaultSource = TYPEPHP_ROOT_PATH . '/phpunit/code/local-literal-declaration-initializer.php';
        $compiler->addFiles([$varIntSource, $defaultSource]);

        $compiler->prepareFile($varIntSource);
        $compiler->prepareFile($defaultSource);
        $this->compilePrepared($compiler, $varIntSource);
        $defaultCode = $this->compilePrepared($compiler, $defaultSource);

        self::assertStringContainsString('php::Int integer = php::toInt(42L);', $defaultCode);
        self::assertStringNotContainsString('php::Var integer = 42L;', $defaultCode);
    }

    public function testNativeIntegerReusedAsForeachKeyKeepsNativeStorage(): void
    {
        $code = $this->compileSource(
            $this->createCompiler(),
            TYPEPHP_ROOT_PATH . '/phpunit/code/foreach-key-native-reuse.php',
        );
        self::assertMatchesRegularExpression('/php::Int index = php::toInt\(100L{1,2}\);/', $code);
        self::assertMatchesRegularExpression('/index = php::toInt\(tmp_var_\d+\);/', $code);
        self::assertSame(1, substr_count($code, '.key()'));
    }

    public function testVarIntAndExplicitAnyCanBeReusedAsForeachKeys(): void
    {
        foreach (['foreach-key-varint-reuse.php', 'foreach-key-any-reuse.php'] as $file) {
            $code = $this->compileSource(
                $this->createCompiler(),
                TYPEPHP_ROOT_PATH . '/phpunit/code/' . $file,
            );
            self::assertStringContainsString('php::Var index', $code);
            self::assertStringNotContainsString('php::Int index', $code);
        }
    }

    public function testNativeScalarUsesDirectReferenceCaptureInNonEscapingClosure(): void
    {
        $code = $this->compileSource(
            $this->createCompiler(),
            TYPEPHP_ROOT_PATH . '/phpunit/code/native-scalar-reference-capture.php',
        );

        self::assertStringContainsString(
            'auto set = [&changed]() mutable -> php::Var {',
            $code,
        );
        self::assertStringNotContainsString('php::newClosureWithParameters(', $code);
    }

    public function testExplicitAnyUsesDirectReferenceCaptureInNonEscapingClosure(): void
    {
        $code = $this->compileSource(
            $this->createCompiler(),
            TYPEPHP_ROOT_PATH . '/phpunit/code/any-reference-capture.php',
        );

        self::assertStringContainsString('php::Var changed', $code);
        self::assertStringContainsString(
            'auto set = [&changed]() mutable -> php::Var {',
            $code,
        );
        self::assertStringNotContainsString('php::newClosureWithParameters(', $code);
    }

    public function testDestructuringKeepsNativeTargetsAndConvertsDynamicItems(): void
    {
        $code = $this->compileSource(
            $this->createCompiler(),
            TYPEPHP_ROOT_PATH . '/phpunit/code/native-destructuring-reuse.php',
        );

        self::assertStringContainsString('php::Int index', $code);
        self::assertMatchesRegularExpression('/index = php::toInt\([^;]+\.item\(0L?/', $code);
        self::assertStringContainsString('php::toInt(', $code);
    }

    public function testForeachDestructuringConvertsExistingNativeTargets(): void
    {
        $code = $this->compileSource(
            $this->createCompiler(),
            TYPEPHP_ROOT_PATH . '/phpunit/code/foreach-destructuring-native-reuse.php',
        );

        foreach (['integer' => 'Int', 'floating' => 'Float', 'boolean' => 'Bool'] as $name => $conversion) {
            self::assertMatchesRegularExpression('/' . $name . ' = php::to' . $conversion . '\([^;]+\.item\(/', $code);
        }
        self::assertStringContainsString('php::Str string', $code);
        self::assertSame(2, preg_match_all('/integer = php::toInt\([^;]+\.item\(/', $code));
    }

    private function createCompiler(): CompilerTest
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        return $compiler;
    }

    private function compileSource(CompilerTest $compiler, string $source): string
    {
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        return $this->compilePrepared($compiler, $source);
    }

    private function compilePrepared(CompilerTest $compiler, string $source): string
    {
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);
        self::assertIsString($code);
        return $code;
    }
}

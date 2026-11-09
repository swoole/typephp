<?php

use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use TypePhp\CompilerBase;
use TypePhp\CompilerTest;
use TypePhp\Context\FunctionContext;
use TypePhp\Exception\TestError;
use TypePhp\Type;

final class ArrayWriteRootTest extends \BaseTest
{
    private function compiler(): CompilerTest
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        (new ReflectionProperty($compiler, 'file'))->setValue($compiler, __FILE__);
        $phase = (new ReflectionClass(CompilerBase::class))->getConstant('PHASE_CONVERT');
        (new ReflectionMethod($compiler, 'enterCompilerPhase'))->invoke($compiler, $phase);
        (new ReflectionMethod($compiler, 'resetFunction'))->invoke($compiler);
        return $compiler;
    }

    private function context(CompilerTest $compiler): FunctionContext
    {
        return (new ReflectionProperty($compiler, 'context'))->getValue($compiler);
    }

    private function parse(CompilerTest $compiler, string $code): void
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $code);
        foreach ($statements as $statement) {
            $compiler->parseExpr($statement->expr);
        }
    }

    public static function arrayWrites(): iterable
    {
        yield 'ordinary key' => ["\$a['x'] = 1;"];
        yield 'append' => ['$a[] = 1;'];
        yield 'nested keys' => ["\$a['x']['y'] = 1;"];
        yield 'nested append' => ["\$a['x'][] = 1;"];
        yield 'coalesce key' => ["\$a['x'] ??= 1;"];
        yield 'nested coalesce' => ["\$a['x']['y'] ??= 1;"];
        yield 'deep coalesce' => ["\$a['x']['y']['z'] ??= 1;"];
        yield 'mixed writes' => ["\$a['x'] ??= 1; \$a[] = 2; \$a['y'] = 3;"];
    }

    #[DataProvider('arrayWrites')]
    public function testUndefinedRootIsDeclaredAsArray(string $code): void
    {
        $compiler = $this->compiler();
        $this->parse($compiler, $code);
        self::assertSame(Type::ARRAY, $this->context($compiler)->localVars['a']);
    }

    #[DataProvider('arrayWrites')]
    public function testDeclaredArrayRootCannotBecomeAScalar(string $code): void
    {
        $compiler = $this->compiler();
        $this->parse($compiler, $code);
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Cannot re-assign `$a` from `php::Int` to `php::Array`');
        $this->parse($compiler, '$a = 5;');
    }

    public static function existingTypes(): iterable
    {
        foreach ([Type::ARRAY, Type::VAR, Type::REF, Type::OBJECT] as $type) {
            yield $type . ' ordinary' => [$type, "\$a['x'] = 1;"];
            yield $type . ' coalesce' => [$type, "\$a['x']['y'] ??= 1;"];
        }
    }

    #[DataProvider('existingTypes')]
    public function testExistingRootKeepsItsType(string $type, string $code): void
    {
        $compiler = $this->compiler();
        $context = $this->context($compiler);
        $context->addLocalVar('a', $type);
        $this->parse($compiler, $code);
        self::assertSame($type, $context->localVars['a']);
    }

    public static function undefinedReads(): iterable
    {
        yield 'dimension read' => ["\$a['x'];"];
        yield 'nested read' => ["\$a['x']['y'];"];
        yield 'coalesce read' => ["\$a['x'] ?? 1;"];
        yield 'property receiver' => ["\$a->items['x'] ??= 1;"];
    }

    #[DataProvider('undefinedReads')]
    public function testReadDoesNotDeclareRoot(string $code): void
    {
        $compiler = $this->compiler();
        try {
            $this->parse($compiler, $code);
            self::fail('An undefined read must be rejected');
        } catch (TestError $error) {
            self::assertStringContainsString('The variable `$a` is undefined', $error->getMessage());
            self::assertArrayNotHasKey('a', $this->context($compiler)->localVars);
        }
    }

    public function testSuperglobalsDoNotBecomeLocalArrays(): void
    {
        $compiler = $this->compiler();
        $this->parse($compiler, "\$_GET['x'] ??= 1; \$GLOBALS['array_write_root']['x'] ??= 2;");
        $this->parse($compiler, "\$GLOBALS['array_write_root']['nested']['y'] = 3;");
        $context = $this->context($compiler);
        self::assertArrayNotHasKey('_GET', $context->localVars);
        self::assertArrayNotHasKey('GLOBALS', $context->localVars);
        self::assertArrayNotHasKey('array_write_root', $context->localVars);
    }

    public function testScalarCoalesceStillDeclaresVariant(): void
    {
        $compiler = $this->compiler();
        $this->parse($compiler, '$a ??= 1;');
        self::assertSame(Type::VAR, $this->context($compiler)->localVars['a']);
    }
}

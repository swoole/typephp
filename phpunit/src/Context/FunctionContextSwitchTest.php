<?php

namespace TypePhp\Tests\Context;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\StaticVar;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;
use TypePhp\Context\FunctionContext;
use TypePhp\Exception\TestError;
use TypePhp\Type;

final class FunctionContextSwitchTest extends TestCase
{
    private CompilerTest $compiler;
    private \ReflectionClass $reflection;

    protected function setUp(): void
    {
        global $translator;
        $this->compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $this->compiler;
        $this->reflection = new \ReflectionClass($this->compiler);
        $this->set('file', __FILE__);
        $this->invoke('enterCompilerPhase', $this->reflection->getConstant('PHASE_CONVERT'));
        $this->invoke('resetFunction');
    }

    private function invoke(string $name, mixed ...$arguments): mixed
    {
        return $this->reflection->getMethod($name)->invoke($this->compiler, ...$arguments);
    }

    private function get(string $name): mixed
    {
        return $this->reflection->getProperty($name)->getValue($this->compiler);
    }

    private function set(string $name, mixed $value): void
    {
        $this->reflection->getProperty($name)->setValue($this->compiler, $value);
    }

    public function testTemporaryContextKeepsItsResultsWithoutChangingTheEnclosingContext(): void
    {
        $outer = $this->get('context');
        $outer->addLocalVar('outer', Type::INT);
        $outer->statements->addBefore('outer_prepare();');
        $outer->statements->addAfter('outer_cleanup();');
        $this->set('indentLevel', 3);
        $this->set('inGeneratorBody', true);
        $propertyContext = new FunctionContext();

        $result = $this->invoke('withFunctionContext', $propertyContext, function () use ($propertyContext): string {
            self::assertSame($propertyContext, $this->get('context'));
            self::assertSame([], $propertyContext->localVars);
            self::assertSame(3, $this->get('indentLevel'));
            self::assertTrue($this->get('inGeneratorBody'));
            $propertyContext->addLocalVar('initializer', Type::ARRAY);
            $propertyContext->statements->addBefore('property_init();');
            $this->set('indentLevel', 8);
            $this->set('inGeneratorBody', false);
            return 'generated';
        });

        self::assertSame('generated', $result);
        self::assertSame($outer, $this->get('context'));
        self::assertSame(['outer' => Type::INT], $outer->localVars);
        self::assertSame(['outer_prepare();'], $outer->statements->takeBefore());
        self::assertSame(['outer_cleanup();'], $outer->statements->takeAfter());
        self::assertSame(3, $this->get('indentLevel'));
        self::assertTrue($this->get('inGeneratorBody'));
        self::assertSame(['initializer' => Type::ARRAY], $propertyContext->localVars);
        self::assertSame(['property_init();'], $propertyContext->statements->takeBefore());
    }

    public function testNestedFailureRestoresEachEnclosingEmissionState(): void
    {
        $outer = $this->get('context');
        $this->set('indentLevel', 2);
        $first = new FunctionContext();
        $failure = new \RuntimeException('nested lowering failed');

        $result = $this->invoke('withFunctionContext', $first, function () use ($first, $failure): int {
            $this->set('indentLevel', 5);
            $this->set('inGeneratorBody', true);
            try {
                $this->invoke('withFunctionContext', new FunctionContext(), function () use ($failure): void {
                    $this->set('indentLevel', 9);
                    $this->set('inGeneratorBody', false);
                    throw $failure;
                });
                self::fail('Expected the nested failure');
            } catch (\RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertSame($first, $this->get('context'));
            self::assertSame(5, $this->get('indentLevel'));
            self::assertTrue($this->get('inGeneratorBody'));
            return 42;
        });

        self::assertSame(42, $result);
        self::assertSame($outer, $this->get('context'));
        self::assertSame(2, $this->get('indentLevel'));
        self::assertFalse($this->get('inGeneratorBody'));
    }

    public function testSameContextGuardRestoresEmissionFlagsOnFailure(): void
    {
        $outer = $this->get('context');
        $this->set('indentLevel', 4);
        $failure = new \RuntimeException('closure lowering failed');
        try {
            $this->invoke('withFunctionContext', $outer, function () use ($failure): void {
                $this->set('indentLevel', 7);
                $this->set('inGeneratorBody', true);
                throw $failure;
            });
            self::fail('Expected the lowering failure');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame($outer, $this->get('context'));
        self::assertSame(4, $this->get('indentLevel'));
        self::assertFalse($this->get('inGeneratorBody'));
    }

    public function testStaticInitializerFailureRestoresTheCallerContext(): void
    {
        $outer = $this->get('context');
        $outer->addLocalVar('existing', Type::INT);
        $outer->tmpVarIndex = 12;
        $outer->statements->addBefore('existing_prepare();');
        $outer->statements->addAfter('existing_cleanup();');
        $this->set('indentLevel', 3);
        $initializer = new StaticVar(new Expr\Variable('cache'), new Expr\Variable(new Expr\Variable('name')));

        try {
            $this->invoke('genStaticVarInitLambda', $initializer, 'cache_slot');
            self::fail('Expected unsupported variable-variable lowering to fail');
        } catch (TestError $error) {
            self::assertStringContainsString('The `$$` syntax is not supported', $error->getMessage());
        }

        self::assertSame($outer, $this->get('context'));
        self::assertSame(['existing' => Type::INT], $outer->localVars);
        self::assertSame(12, $outer->tmpVarIndex);
        self::assertSame(['existing_prepare();'], $outer->statements->takeBefore());
        self::assertSame(['existing_cleanup();'], $outer->statements->takeAfter());
        self::assertSame(3, $this->get('indentLevel'));
        self::assertFalse($this->get('inGeneratorBody'));
    }

    public function testStaticInitializerKeepsTheEnclosingTemporaryNamespace(): void
    {
        $outer = $this->get('context');
        $outer->addLocalVar('tmp_var_0', Type::INT);
        $outer->tmpVarIndex = 10;
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php static $cache = [...[1]];');
        $initializer = $ast[0]->vars[0];
        $code = $this->invoke('genStaticVarInitLambda', $initializer, 'cache_slot');

        self::assertStringContainsString('tmp_var_10', $code);
        self::assertStringNotContainsString('tmp_var_0', $code);
        self::assertSame($outer, $this->get('context'));
        self::assertSame(['tmp_var_0' => Type::INT], $outer->localVars);
        self::assertSame(10, $outer->tmpVarIndex);
        self::assertFalse($outer->statements->hasPending());
    }

    public function testParameterDefaultFailureRestoresTheCallerContext(): void
    {
        $outer = $this->get('context');
        $this->set('indentLevel', 2);
        try {
            $this->invoke('parseParamDefaultValue', new Expr\Variable(new Expr\Variable('name')));
            self::fail('Expected unsupported variable-variable lowering to fail');
        } catch (TestError $error) {
            self::assertStringContainsString('The `$$` syntax is not supported', $error->getMessage());
        }
        self::assertSame($outer, $this->get('context'));
        self::assertSame(2, $this->get('indentLevel'));
        self::assertFalse($this->get('inGeneratorBody'));
    }
}

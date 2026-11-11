<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;
use TypePhp\Context\FunctionContext;

final class CallableScopeStateTest extends TestCase
{
    public function testCalledClassRequirementIncludesItsEntryAndCannotBeDowngraded(): void
    {
        $context = new FunctionContext();
        self::assertFalse($context->needsCalledCe());
        self::assertFalse($context->needsCalledClass());
        self::assertSame('_typephp_called_class', $context->requireCalledClass());
        self::assertTrue($context->needsCalledCe());
        self::assertTrue($context->needsCalledClass());
        self::assertSame('_typephp_called_ce', $context->requireCalledCe());
        self::assertTrue($context->needsCalledClass());
        self::assertNull($context->getCallableScopeVariable());
        self::assertSame(0, $context->tmpVarIndex);
    }

    public function testScopeReservationAndGuardReuseOneDeclaration(): void
    {
        $context = new FunctionContext();
        self::assertNull($context->getCallableScopeVariable());
        $name = $context->getOrCreateCallableScope(fn() => 'tmp_var_' . $context->tmpVarIndex++);
        self::assertFalse($context->needsUserCodeCallableScope());
        $unexpected = static function (): string {
            throw new \LogicException('The existing declaration must be reused');
        };
        $context->requireUserCodeCallableScope($unexpected);
        self::assertSame($name, $context->getOrCreateCallableScope($unexpected));
        self::assertSame($name, $context->getCallableScopeVariable());
        self::assertTrue($context->needsUserCodeCallableScope());
        self::assertSame(1, $context->tmpVarIndex);
        self::assertSame([], $context->localVars);
    }

    public function testFailedGuardReservationDoesNotPartiallyMarkTheBody(): void
    {
        $context = new FunctionContext();
        $context->requireCalledClass();
        $failure = new \RuntimeException('scope reservation failed');
        try {
            $context->requireUserCodeCallableScope(function () use ($context, $failure): string {
                $context->tmpVarIndex++;
                throw $failure;
            });
            self::fail('Expected the allocator failure');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertFalse($context->needsUserCodeCallableScope());
        self::assertNull($context->getCallableScopeVariable());
        self::assertTrue($context->needsCalledCe());
        self::assertTrue($context->needsCalledClass());
        self::assertSame(1, $context->tmpVarIndex);
        $context->requireUserCodeCallableScope(fn() => 'tmp_var_' . $context->tmpVarIndex++);
        self::assertSame('tmp_var_1', $context->getCallableScopeVariable());
        self::assertTrue($context->needsUserCodeCallableScope());
    }

    #[DataProvider('analysisExits')]
    public function testAnalysisResetsLateStaticScratchAndPreservesExistingScopeRequirements(bool $fail): void
    {
        $context = new FunctionContext();
        $context->requireUserCodeCallableScope(static fn() => 'outer_scope');
        $failure = new \RuntimeException('analysis failed');
        try {
            $context->withAnalysisTemporaries(function () use ($context, $failure, $fail): void {
                $context->requireCalledClass();
                if ($fail) {
                    throw $failure;
                }
            });
        } catch (\RuntimeException $caught) {
            self::assertTrue($fail);
            self::assertSame($failure, $caught);
        }
        self::assertFalse($context->needsCalledCe());
        self::assertFalse($context->needsCalledClass());
        self::assertSame('outer_scope', $context->getCallableScopeVariable());
        self::assertTrue($context->needsUserCodeCallableScope());

        $inner = new FunctionContext();
        self::assertNull($inner->getCallableScopeVariable());
        self::assertFalse($inner->needsUserCodeCallableScope());
    }

    public static function analysisExits(): array
    {
        return ['success' => [false], 'exception' => [true]];
    }

    public function testGlobalCallableScopeFallbackDoesNotReserveADeclarationOrGuard(): void
    {
        global $translator;
        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        (new \ReflectionMethod($compiler, 'resetFunction'))->invoke($compiler);
        $context = (new \ReflectionProperty($compiler, 'context'))->getValue($compiler);
        self::assertSame(
            'php::CallableScope(nullptr, nullptr, nullptr)',
            (new \ReflectionMethod($compiler, 'getCallableScopeExpr'))->invoke($compiler),
        );
        (new \ReflectionMethod($compiler, 'markUserCodeCallableScope'))->invoke($compiler);
        self::assertNull($context->getCallableScopeVariable());
        self::assertFalse($context->needsUserCodeCallableScope());
        self::assertSame(0, $context->tmpVarIndex);
    }
}

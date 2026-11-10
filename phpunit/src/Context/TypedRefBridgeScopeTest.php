<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;
use TypePhp\Type;

final class TypedRefBridgeScopeTest extends TestCase
{
    private function aliasedContext(): FunctionContext
    {
        $context = new FunctionContext();
        $context->addLocalVar('root', Type::INT);
        $context->registerTypedRefLocal('first', 'root', Type::INT_REF);
        $context->registerTypedRefLocal('second', 'first', Type::INT_REF);
        return $context;
    }

    private function assertNoActiveScope(FunctionContext $context): void
    {
        try {
            $context->findTypedRefBridge('root');
            self::fail('A bridge lookup must require an active call scope');
        } catch (\LogicException $error) {
            self::assertStringContainsString('active call-argument scope', $error->getMessage());
        }
    }

    public function testAliasChainsKeepTheirBindingInitializersAndShareOneCanonicalRoot(): void
    {
        $context = $this->aliasedContext();
        self::assertSame('root', $context->getTypedRefRoot('root'));
        self::assertSame('root', $context->getTypedRefRoot('first'));
        self::assertSame('root', $context->getTypedRefRoot('second'));
        self::assertSame('first', $context->typedRefBindings['second']);
        self::assertSame('first', $context->localVarInitializers['second']);
        self::assertSame(['first' => true, 'second' => true], $context->typedRefAliases['root']);

        $result = $context->withTypedRefBridgeScope(function () use ($context): string {
            self::assertNull($context->findTypedRefBridge('first'));
            $context->registerTypedRefBridge('second', 'shared_wrapper');
            self::assertSame('shared_wrapper', $context->findTypedRefBridge('root'));
            self::assertSame('shared_wrapper', $context->findTypedRefBridge('first'));
            return $context->findTypedRefBridge('second');
        });
        self::assertSame('shared_wrapper', $result);
        $this->assertNoActiveScope($context);
    }

    public function testNestedCallsUseIndependentWrappersAndRestoreTheOuterIdentity(): void
    {
        $context = $this->aliasedContext();
        $context->withTypedRefBridgeScope(function () use ($context): void {
            $context->registerTypedRefBridge('first', 'outer_wrapper');
            $inner = $context->withTypedRefBridgeScope(function () use ($context): string {
                self::assertNull($context->findTypedRefBridge('root'));
                $context->registerTypedRefBridge('second', 'inner_wrapper');
                return $context->findTypedRefBridge('first');
            });
            self::assertSame('inner_wrapper', $inner);
            self::assertSame('outer_wrapper', $context->findTypedRefBridge('second'));
        });
        $this->assertNoActiveScope($context);
    }

    public function testFailedInnerCallRestoresTheOuterWrapper(): void
    {
        $context = $this->aliasedContext();
        $failure = new \RuntimeException('inner argument lowering failed');
        $context->withTypedRefBridgeScope(function () use ($context, $failure): void {
            $context->registerTypedRefBridge('root', 'outer_wrapper');
            try {
                $context->withTypedRefBridgeScope(function () use ($context, $failure): void {
                    $context->registerTypedRefBridge('second', 'discarded_wrapper');
                    throw $failure;
                });
                self::fail('Expected the nested lowering failure');
            } catch (\RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertSame('outer_wrapper', $context->findTypedRefBridge('first'));
        });
        $this->assertNoActiveScope($context);
    }

    public function testFailedOuterCallDoesNotLeakWrappersIntoTheNextCall(): void
    {
        $context = $this->aliasedContext();
        $failure = new \RuntimeException('outer argument lowering failed');
        try {
            $context->withTypedRefBridgeScope(function () use ($context, $failure): void {
                $context->registerTypedRefBridge('root', 'discarded_wrapper');
                throw $failure;
            });
            self::fail('Expected the lowering failure');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $this->assertNoActiveScope($context);
        $context->withTypedRefBridgeScope(function () use ($context): void {
            self::assertNull($context->findTypedRefBridge('first'));
        });
    }

    public function testRegistrationWithoutACallScopeIsRejected(): void
    {
        $context = $this->aliasedContext();
        $this->assertNoActiveScope($context);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('active call-argument scope');
        $context->registerTypedRefBridge('root', 'invalid_wrapper');
    }

    public function testConflictingAliasWrapperCannotReplaceTheOriginalBridge(): void
    {
        $context = $this->aliasedContext();
        $context->withTypedRefBridgeScope(function () use ($context): void {
            $context->registerTypedRefBridge('root', 'first_wrapper');
            $context->registerTypedRefBridge('second', 'first_wrapper');
            try {
                $context->registerTypedRefBridge('first', 'competing_wrapper');
                self::fail('Aliases must not own competing write-back wrappers');
            } catch (\LogicException $error) {
                self::assertStringContainsString('reuse one bridge', $error->getMessage());
            }
            self::assertSame('first_wrapper', $context->findTypedRefBridge('second'));
            $context->registerTypedRefBridge('unrelated', 'other_wrapper');
            self::assertSame('other_wrapper', $context->findTypedRefBridge('unrelated'));
        });
    }

    public function testEachFunctionContextOwnsItsCallScopes(): void
    {
        $first = $this->aliasedContext();
        $second = $this->aliasedContext();
        $first->withTypedRefBridgeScope(function () use ($first, $second): void {
            $first->registerTypedRefBridge('root', 'first_context_wrapper');
            $this->assertNoActiveScope($second);
            $second->withTypedRefBridgeScope(function () use ($second): void {
                self::assertNull($second->findTypedRefBridge('root'));
                $second->registerTypedRefBridge('first', 'second_context_wrapper');
                self::assertSame('second_context_wrapper', $second->findTypedRefBridge('second'));
            });
            self::assertSame('first_context_wrapper', $first->findTypedRefBridge('root'));
        });
        $this->assertNoActiveScope($first);
        $this->assertNoActiveScope($second);
    }

    public function testAnalysisResetDiscardsAliasAndBridgeState(): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('root', Type::INT);
        $context->withAnalysisTemporaries(function () use ($context): void {
            $context->registerTypedRefLocal('first', 'root', Type::INT_REF);
            $context->registerTypedRefLocal('second', 'first', Type::INT_REF);
            $context->withTypedRefBridgeScope(function () use ($context): void {
                $context->registerTypedRefBridge('first', 'old_wrapper');
            });
        });
        self::assertSame(['root' => Type::INT], $context->localVars);
        self::assertSame([], $context->typedRefBindings);
        self::assertSame([], $context->typedRefAliases);
        self::assertSame([], $context->typedRefRoots);
        self::assertSame('second', $context->getTypedRefRoot('second'));
        $this->assertNoActiveScope($context);
        $context->withTypedRefBridgeScope(function () use ($context): void {
            self::assertNull($context->findTypedRefBridge('root'));
        });
    }

    public function testInvalidReferenceRegistrationDoesNotPartiallyRegisterALocal(): void
    {
        $context = new FunctionContext();
        try {
            $context->registerTypedRefLocal('invalid', 'root', Type::VAR);
            self::fail('A typed reference requires fixed reference storage');
        } catch (\LogicException $error) {
            self::assertStringContainsString('typed-ref type', $error->getMessage());
        }
        self::assertSame([], $context->localVars);
        self::assertSame([], $context->localVarInitializers);
        self::assertSame([], $context->typedRefBindings);
        self::assertSame([], $context->typedRefRoots);
        self::assertSame([], $context->typedRefAliases);
    }
}

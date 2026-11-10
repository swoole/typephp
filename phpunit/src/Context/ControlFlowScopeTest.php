<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;
use TypePhp\Context\ScopeContext;
use TypePhp\Type;

final class ControlFlowScopeTest extends TestCase
{
    public function testNestedLexicalScopesRestoreLayoutsAndKeepFunctionLocals(): void
    {
        $context = new FunctionContext();
        $failure = new \RuntimeException('nested scope failed');
        $result = $context->withScope(function () use ($context, $failure): int {
            self::assertSame(1, $context->scopeLevel);
            $outerLayout = $context->scopeLayouts[0];
            self::assertInstanceOf(ScopeContext::class, $outerLayout);
            try {
                $context->withScope(function () use ($context, $failure): void {
                    self::assertSame(2, $context->scopeLevel);
                    self::assertCount(2, $context->scopeLayouts);
                    $context->addLocalVar('value', Type::INT);
                    throw $failure;
                });
                self::fail('Expected the nested failure');
            } catch (\RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertSame(1, $context->scopeLevel);
            self::assertSame([$outerLayout], $context->scopeLayouts);
            return 42;
        });

        self::assertSame(42, $result);
        self::assertSame(0, $context->scopeLevel);
        self::assertSame([], $context->scopeLayouts);
        self::assertSame(['value' => Type::INT], $context->localVars);
    }

    public function testSwitchDoesNotIntroduceAContinuableLoop(): void
    {
        $context = new FunctionContext();
        $result = $context->withBreakableContext(true, function () use ($context): string {
            self::assertTrue($context->inLoop);
            self::assertFalse($context->inContinuableLoop);
            self::assertTrue($context->breakableIsSwitch);
            self::assertSame(1, $context->breakableDepth);
            return 'switch';
        });

        self::assertSame('switch', $result);
        $this->assertOutsideLoop($context);
    }

    public function testLoopInsideSwitchRestoresTheSwitchOnFailure(): void
    {
        $context = new FunctionContext();
        $failure = new \RuntimeException('loop failed');
        try {
            $context->withBreakableContext(true, function () use ($context, $failure): void {
                try {
                    $context->withBreakableContext(false, function () use ($context, $failure): void {
                        self::assertTrue($context->inLoop);
                        self::assertTrue($context->inContinuableLoop);
                        self::assertFalse($context->breakableIsSwitch);
                        self::assertSame(2, $context->breakableDepth);
                        $context->hasMultiLevelBreak = true;
                        throw $failure;
                    });
                } finally {
                    self::assertTrue($context->inLoop);
                    self::assertFalse($context->inContinuableLoop);
                    self::assertTrue($context->breakableIsSwitch);
                    self::assertSame(1, $context->breakableDepth);
                }
            });
            self::fail('Expected the loop failure');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        $this->assertOutsideLoop($context);
        self::assertTrue($context->hasMultiLevelBreak);
    }

    public function testSwitchInsideLoopRetainsContinuePermissionAndCountsEveryLevel(): void
    {
        $context = new FunctionContext();
        $context->withBreakableContext(false, function () use ($context): void {
            $context->withBreakableContext(true, function () use ($context): void {
                self::assertTrue($context->inContinuableLoop);
                self::assertTrue($context->breakableIsSwitch);
                self::assertSame(2, $context->breakableDepth);
                $context->withBreakableContext(true, function () use ($context): void {
                    self::assertTrue($context->inContinuableLoop);
                    self::assertSame(3, $context->breakableDepth);
                });
                self::assertSame(2, $context->breakableDepth);
            });
            self::assertTrue($context->inContinuableLoop);
            self::assertFalse($context->breakableIsSwitch);
            self::assertSame(1, $context->breakableDepth);
        });
        $this->assertOutsideLoop($context);
    }

    private function assertOutsideLoop(FunctionContext $context): void
    {
        self::assertFalse($context->inLoop);
        self::assertFalse($context->inContinuableLoop);
        self::assertFalse($context->breakableIsSwitch);
        self::assertSame(0, $context->breakableDepth);
    }
}

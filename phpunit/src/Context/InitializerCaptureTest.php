<?php

namespace TypePhp\Tests\Context;

use PhpParser\Node\Expr;
use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;
use TypePhp\Type;

final class InitializerCaptureTest extends TestCase
{
    public function testCaptureKeepsCallerWorkAndDeclarationMetadata(): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('outer', Type::INT);
        $context->statements->addBefore('outer_prepare();');
        $context->statements->addAfter('outer_cleanup();');
        $node = new Expr\Variable('value');
        $cache = $context->materializedExpressions;
        $cache[$node] = 'outer_value';

        $capture = $context->captureInitializer(function () use ($context, $cache, $node): string {
            self::assertNotSame($cache, $context->materializedExpressions);
            self::assertCount(0, $context->materializedExpressions);
            $context->addLocalVar('temporary', Type::INT);
            $context->localVarInitializers['temporary'] = '42L';
            $context->materializedExpressions[$node] = 'temporary';
            $context->statements->addBefore('temporary = compute();');
            $context->statements->addAfter('release();');
            return 'temporary';
        });

        self::assertSame(['temporary', ['temporary' => Type::INT], ['temporary = compute();'], ['release();']], $capture);
        self::assertSame(['outer' => Type::INT], $context->localVars);
        self::assertSame('42L', $context->localVarInitializers['temporary']);
        self::assertSame($cache, $context->materializedExpressions);
        self::assertSame('outer_value', $cache[$node]);
        self::assertSame(['outer_prepare();'], $context->statements->takeBefore());
        self::assertSame(['outer_cleanup();'], $context->statements->takeAfter());
    }

    public function testFailureRestoresOwnedStateAndKeepsTemporaryNumbering(): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('outer', Type::INT);
        $context->tmpVarIndex = 7;
        $context->statements->addBefore('outer_prepare();');
        $context->statements->addAfter('outer_cleanup();');
        $cache = $context->materializedExpressions;
        $failure = new \RuntimeException('initializer failed');
        try {
            $context->captureInitializer(function () use ($context, $failure): void {
                $context->addLocalVar('tmp_var_' . $context->tmpVarIndex++, Type::ARRAY);
                $context->materializedExpressions[new Expr\Variable('value')] = 'temporary';
                $context->statements->addBefore('partial_prepare();');
                $context->statements->addAfter('partial_cleanup();');
                throw $failure;
            });
            self::fail('Expected the initializer failure');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(['outer' => Type::INT], $context->localVars);
        self::assertSame(8, $context->tmpVarIndex);
        self::assertSame($cache, $context->materializedExpressions);
        self::assertCount(0, $cache);
        self::assertSame(['outer_prepare();'], $context->statements->takeBefore());
        self::assertSame(['outer_cleanup();'], $context->statements->takeAfter());
        self::assertSame([42, [], [], []], $context->captureInitializer(static fn() => 42));
    }

    public function testNestedCapturesRestoreEachEnclosingCacheAndLocalSuffix(): void
    {
        $context = new FunctionContext();
        $entryCache = $context->materializedExpressions;
        $node = new Expr\Variable('value');
        $outer = $context->captureInitializer(function () use ($context, $node): string {
            $context->addLocalVar('outer_tmp', Type::VAR);
            $context->materializedExpressions[$node] = 'outer_tmp';
            $outerCache = $context->materializedExpressions;
            $context->statements->addBefore('outer_tmp = outer();');
            $inner = $context->captureInitializer(function () use ($context, $node): string {
                self::assertCount(0, $context->materializedExpressions);
                $context->addLocalVar('inner_tmp', Type::VAR);
                $context->materializedExpressions[$node] = 'inner_tmp';
                $context->statements->addBefore('inner_tmp = inner();');
                return 'inner_tmp';
            });
            self::assertSame(['inner_tmp', ['inner_tmp' => Type::VAR], ['inner_tmp = inner();'], []], $inner);
            self::assertSame(['outer_tmp' => Type::VAR], $context->localVars);
            self::assertSame($outerCache, $context->materializedExpressions);
            self::assertSame('outer_tmp', $outerCache[$node]);
            return 'outer_tmp';
        });

        self::assertSame(['outer_tmp', ['outer_tmp' => Type::VAR], ['outer_tmp = outer();'], []], $outer);
        self::assertSame([], $context->localVars);
        self::assertSame($entryCache, $context->materializedExpressions);
        self::assertFalse($context->statements->hasPending());
    }

    public function testCaptureDoesNotRollBackChangesToExistingBindings(): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('outer', Type::INT);
        $capture = $context->captureInitializer(function () use ($context): void {
            $context->setInferredLocalType('outer', Type::VAR);
            $context->planVariantStorage('later');
            $context->addLocalVar('temporary', Type::ARRAY);
        });

        self::assertSame(['temporary' => Type::ARRAY], $capture[1]);
        self::assertSame(['outer' => Type::VAR], $context->localVars);
        self::assertSame(['later' => Type::VAR], $context->varTypeDegradations);
    }
}

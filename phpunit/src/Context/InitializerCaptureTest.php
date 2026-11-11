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
        $context->registerMaterializedExpression($node, 'outer', 'outer = seed();');

        $capture = $context->captureInitializer(function () use ($context, $node): string {
            self::assertNull($context->getMaterializedExpression($node));
            $context->addLocalVar('temporary', Type::INT);
            $context->localVarInitializers['temporary'] = '42L';
            $context->registerMaterializedExpression($node, 'temporary', 'temporary = compute();');
            $context->statements->addAfter('release();');
            return 'temporary';
        });

        self::assertSame(['temporary', ['temporary' => Type::INT], ['temporary = compute();'], ['release();']], $capture);
        self::assertSame(['outer' => Type::INT], $context->localVars);
        self::assertSame('42L', $context->localVarInitializers['temporary']);
        self::assertSame('outer', $context->getMaterializedExpression($node));
        self::assertSame(['outer_prepare();', 'outer = seed();'], $context->statements->takeBefore());
        self::assertSame(['outer_cleanup();'], $context->statements->takeAfter());
    }

    public function testFailureRestoresOwnedStateAndKeepsTemporaryNumbering(): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('outer', Type::INT);
        $context->tmpVarIndex = 7;
        $context->statements->addBefore('outer_prepare();');
        $context->statements->addAfter('outer_cleanup();');
        $node = new Expr\Variable('value');
        $failure = new \RuntimeException('initializer failed');
        try {
            $context->captureInitializer(function () use ($context, $failure, $node): void {
                $temporary = 'tmp_var_' . $context->tmpVarIndex++;
                $context->addLocalVar($temporary, Type::ARRAY);
                $context->registerMaterializedExpression($node, $temporary, $temporary . ' = partial_prepare();');
                $context->statements->addAfter('partial_cleanup();');
                throw $failure;
            });
            self::fail('Expected the initializer failure');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(['outer' => Type::INT], $context->localVars);
        self::assertSame(8, $context->tmpVarIndex);
        self::assertNull($context->getMaterializedExpression($node));
        self::assertSame(['outer_prepare();'], $context->statements->takeBefore());
        self::assertSame(['outer_cleanup();'], $context->statements->takeAfter());
        self::assertSame([42, [], [], []], $context->captureInitializer(static fn() => 42));
    }

    public function testNestedCapturesRestoreEachEnclosingCacheAndLocalSuffix(): void
    {
        $context = new FunctionContext();
        $node = new Expr\Variable('value');
        $outer = $context->captureInitializer(function () use ($context, $node): string {
            $context->addLocalVar('outer_tmp', Type::VAR);
            $context->registerMaterializedExpression($node, 'outer_tmp', 'outer_tmp = outer();');
            $inner = $context->captureInitializer(function () use ($context, $node): string {
                self::assertNull($context->getMaterializedExpression($node));
                $context->addLocalVar('inner_tmp', Type::VAR);
                $context->registerMaterializedExpression($node, 'inner_tmp', 'inner_tmp = inner();');
                return 'inner_tmp';
            });
            self::assertSame(['inner_tmp', ['inner_tmp' => Type::VAR], ['inner_tmp = inner();'], []], $inner);
            self::assertSame(['outer_tmp' => Type::VAR], $context->localVars);
            self::assertSame('outer_tmp', $context->getMaterializedExpression($node));
            return 'outer_tmp';
        });

        self::assertSame(['outer_tmp', ['outer_tmp' => Type::VAR], ['outer_tmp = outer();'], []], $outer);
        self::assertSame([], $context->localVars);
        self::assertNull($context->getMaterializedExpression($node));
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

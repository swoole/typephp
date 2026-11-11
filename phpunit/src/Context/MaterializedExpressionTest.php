<?php

namespace TypePhp\Tests\Context;

use PhpParser\Node\Expr;
use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;
use TypePhp\Type;

final class MaterializedExpressionTest extends TestCase
{
    public function testPublishingRequiresStorageAndAnAssignmentWithoutPartialReplacement(): void
    {
        $context = new FunctionContext();
        $node = new Expr\Variable('value');
        $context->addLocalVar('first', Type::VAR);
        $context->registerMaterializedExpression($node, 'first', 'first = evaluate();');

        foreach ([['missing', 'missing = evaluate();'], ['first', '']] as [$name, $assignment]) {
            try {
                $context->registerMaterializedExpression($node, $name, $assignment);
                self::fail('Expected rejection before publishing an invalid materialization');
            } catch (\LogicException) {
                self::assertSame('first', $context->getMaterializedExpression($node));
                self::assertSame(['first = evaluate();'], $context->statements->peekBefore());
            }
        }
    }

    public function testAstIdentityAndContextDetermineWhichResultCanBeReused(): void
    {
        $context = new FunctionContext();
        $node = new Expr\Variable('value');
        $otherNode = clone $node;
        $context->addLocalVar('temporary', Type::VAR);
        $context->registerMaterializedExpression($node, 'temporary', 'temporary = evaluate();');
        self::assertSame('temporary', $context->getMaterializedExpression($node));
        self::assertNull($context->getMaterializedExpression($otherNode));

        $other = new FunctionContext();
        $other->addLocalVar('temporary', Type::VAR);
        self::assertNull($other->getMaterializedExpression($node));
        self::assertFalse($other->statements->hasPending());
        $other->registerMaterializedExpression($otherNode, 'temporary', 'temporary = other();');
        self::assertNull($context->getMaterializedExpression($otherNode));
        self::assertSame(['temporary = evaluate();'], $context->statements->peekBefore());
        self::assertSame(['temporary = other();'], $other->statements->peekBefore());
    }

    public function testExplicitRematerializationPublishesTheLatestAssignmentInOrder(): void
    {
        $context = new FunctionContext();
        $node = new Expr\Variable('value');
        $context->addLocalVar('first', Type::VAR);
        $context->addLocalVar('second', Type::VAR);
        $context->registerMaterializedExpression($node, 'first', 'first = evaluate();');
        $context->registerMaterializedExpression($node, 'second', 'second = evaluate();');

        self::assertSame('second', $context->getMaterializedExpression($node));
        self::assertSame(['first = evaluate();', 'second = evaluate();'], $context->statements->takeBefore());
        self::assertSame([], $context->statements->takeAfter());
    }
}

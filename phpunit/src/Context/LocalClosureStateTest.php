<?php

namespace TypePhp\Tests\Context;

use PhpParser\Node\Expr;
use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;
use TypePhp\Type;

final class LocalClosureStateTest extends TestCase
{
    private function plan(FunctionContext $context, string $name = 'callback'): Expr\Assign
    {
        $assignment = new Expr\Assign(new Expr\Variable($name), new Expr\Closure());
        $context->setLocalClosureCandidates([$name => [
            'assignment' => $assignment,
            'closure' => $assignment->expr,
            'calls' => 1,
        ]]);
        return $assignment;
    }

    public function testLookupRequiresThePlannedAstAndDoesNotCommitStorage(): void
    {
        $context = new FunctionContext();
        $assignment = $this->plan($context);

        self::assertSame($assignment->expr, $context->getLocalClosureCandidate($assignment)['closure']);
        self::assertNull($context->getLocalClosureCandidate(clone $assignment));
        self::assertNull($context->getLocalClosureCandidate(
            new Expr\Assign(new Expr\Variable('missing'), $assignment->expr),
        ));
        self::assertNull($context->getLocalClosureCandidate(
            new Expr\Assign(new Expr\Variable(new Expr\Variable('name')), $assignment->expr),
        ));
        self::assertNull($context->getLocalClosureCandidate(
            new Expr\Assign(new Expr\ArrayDimFetch(new Expr\Variable('items')), $assignment->expr),
        ));
        self::assertFalse($context->isNativeLocalClosure('callback'));
        self::assertSame([], $context->localVars);
    }

    public function testCommittedBindingUsesTheEscapedNameAndRetainsItsPlan(): void
    {
        $context = new FunctionContext();
        $assignment = $this->plan($context, 'class');
        $name = '_php__var__class';
        $context->registerNativeLocalClosure($name, $assignment);

        self::assertTrue($context->isNativeLocalClosure($name));
        self::assertFalse($context->isNativeLocalClosure('class'));
        self::assertSame([$name => Type::OBJECT], $context->localVars);
        self::assertSame($assignment, $context->getLocalClosureCandidate($assignment)['assignment']);

        $context->registerNativeLocalClosure($name, $assignment);
        self::assertSame([$name => Type::OBJECT], $context->localVars);
        self::assertSame($assignment->expr, $context->getLocalClosureCandidate($assignment)['closure']);
    }

    public function testUnplannedRegistrationDoesNotPartiallyCommitStorage(): void
    {
        $context = new FunctionContext();
        $assignment = $this->plan($context);
        try {
            $context->registerNativeLocalClosure('callback', clone $assignment);
            self::fail('Expected rejection of another assignment with the same variable name');
        } catch (\LogicException) {
            self::assertFalse($context->isNativeLocalClosure('callback'));
            self::assertSame([], $context->localVars);
            self::assertSame($assignment, $context->getLocalClosureCandidate($assignment)['assignment']);
        }
    }

    public function testReplacingPlansAfterEmissionPreservesTheCommittedState(): void
    {
        $context = new FunctionContext();
        $assignment = $this->plan($context);
        $context->registerNativeLocalClosure('callback', $assignment);
        try {
            $context->setLocalClosureCandidates([]);
            self::fail('Expected rejection of plan replacement after emission');
        } catch (\LogicException) {
            self::assertTrue($context->isNativeLocalClosure('callback'));
            self::assertSame(['callback' => Type::OBJECT], $context->localVars);
            self::assertSame($assignment, $context->getLocalClosureCandidate($assignment)['assignment']);
        }
    }

    public function testNestedContextsDoNotInheritPlansOrCommittedBindings(): void
    {
        $outer = new FunctionContext();
        $assignment = $this->plan($outer);
        $outer->registerNativeLocalClosure('callback', $assignment);
        $inner = new FunctionContext();

        self::assertNull($inner->getLocalClosureCandidate($assignment));
        self::assertFalse($inner->isNativeLocalClosure('callback'));
        $innerAssignment = $this->plan($inner);
        self::assertNull($inner->getLocalClosureCandidate($assignment));
        self::assertNull($outer->getLocalClosureCandidate($innerAssignment));
        self::assertFalse($inner->isNativeLocalClosure('callback'));
        self::assertTrue($outer->isNativeLocalClosure('callback'));
    }
}

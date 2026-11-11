<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;
use TypePhp\Type;

final class ObjectRefinementTest extends TestCase
{
    public function testNestedRefinementsAndExceptionalExitRestoreOnlyTheRefinedBinding(): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('value', Type::VAR);
        $context->declareObjectClass('value', 'Original');
        $context->bindObjectDispatchClass('value', 'Original');
        $context->recordStableObjectClass('value', 'Original', true);
        try {
            $context->withRefinedObjectClasses(['value' => ['class' => 'FinalValue', 'exact' => true]], function () use ($context): void {
                self::assertSame(Type::VAR, $context->localVars['value']);
                self::assertSame('FinalValue', $context->getRefinedObjectClass('value'));
                self::assertSame('FinalValue', $context->objects['value']);
                $context->bindObjectDispatchClass('other', 'Other');
                $context->withRefinedObjectClasses(['value' => ['class' => 'BaseValue', 'exact' => false]], function () use ($context): void {
                    self::assertSame('BaseValue', $context->declaredObjects['value']);
                    self::assertArrayNotHasKey('value', $context->objects);
                    self::assertArrayNotHasKey('value', $context->stableObjects);
                });
                self::assertSame('FinalValue', $context->objects['value']);
                throw new \RuntimeException('Exit the branch');
            });
            self::fail('Expected the callback exception');
        } catch (\RuntimeException $error) {
            self::assertSame('Exit the branch', $error->getMessage());
        }
        self::assertSame('Original', $context->objects['value']);
        self::assertSame('Original', $context->declaredObjects['value']);
        self::assertSame('Original', $context->stableObjects['value']);
        self::assertSame('Original', $context->exactObjects['value']);
        self::assertSame('', $context->getRefinedObjectClass('value'));
        self::assertSame('Other', $context->objects['other']);
    }
}

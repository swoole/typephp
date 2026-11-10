<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;
use TypePhp\Type;

final class ImmutableBindingTest extends TestCase
{
    public function testObjectIdentityAlwaysCarriesAnImmutableBinding(): void
    {
        $context = new FunctionContext();
        $context->markImmutableBinding('scalar');
        $context->markImmutableBinding('object', true);
        self::assertSame(['scalar' => true, 'object' => true], $context->immutableVars);
        self::assertSame(['object' => true], $context->immutableObjectVars);
    }

    public function testWeakerMarksCannotRemoveAnExistingObjectContract(): void
    {
        $context = new FunctionContext();
        $context->markImmutableBinding('value', true);
        $context->markImmutableBinding('value');
        self::assertTrue($context->immutableVars['value']);
        self::assertTrue($context->immutableObjectVars['value']);
    }

    public function testImportCopiesOnlyTheSelectedContractWithoutDispatchOrStorage(): void
    {
        $source = new FunctionContext();
        $source->markImmutableBinding('scalar');
        $source->markImmutableBinding('object', true);
        $source->markImmutableBinding('uncaptured', true);
        $source->addLocalVar('object', Type::OBJECT);
        $source->bindObjectDispatchClass('object', 'Example');
        $target = new FunctionContext();
        $target->importImmutableBinding($source, 'scalar');
        $target->importImmutableBinding($source, 'object');
        self::assertSame(['scalar' => true, 'object' => true], $target->immutableVars);
        self::assertSame(['object' => true], $target->immutableObjectVars);
        self::assertSame([], $target->localVars);
        self::assertSame([], $target->objects);
    }

    public function testMutableSourceCannotCreateAContractEvenForAnObjectReceiver(): void
    {
        $source = new FunctionContext();
        $source->bindObjectDispatchClass('mutable', 'Example');
        $target = new FunctionContext();
        $target->markImmutableBinding('existing', true);
        $target->importImmutableBinding($source, 'mutable', true);
        self::assertSame(['existing' => true], $target->immutableVars);
        self::assertSame(['existing' => true], $target->immutableObjectVars);
    }

    public function testImportedContractsRemainIndependentUntilExplicitlyImportedAgain(): void
    {
        $source = new FunctionContext();
        $source->markImmutableBinding('value');
        $target = new FunctionContext();
        $target->importImmutableBinding($source, 'value');
        $source->markImmutableBinding('value', true);
        self::assertSame([], $target->immutableObjectVars);
        $target->importImmutableBinding($source, 'value');
        self::assertTrue($target->immutableObjectVars['value']);
        $target->markImmutableBinding('target_only', true);
        self::assertArrayNotHasKey('target_only', $source->immutableVars);
        self::assertArrayNotHasKey('target_only', $source->immutableObjectVars);
    }

    public function testKnownReceiverImportCanStrengthenObjectIdentity(): void
    {
        $source = new FunctionContext();
        $source->markImmutableBinding('this_');
        $target = new FunctionContext();
        $target->importImmutableBinding($source, 'this_', true);
        self::assertTrue($target->immutableVars['this_']);
        self::assertTrue($target->immutableObjectVars['this_']);
        self::assertSame([], $source->immutableObjectVars);
    }

    public function testAnalysisResetKeepsContractsWhileFreshContextsStartEmpty(): void
    {
        $context = new FunctionContext();
        $context->withAnalysisTemporaries(function () use ($context): void {
            $context->markImmutableBinding('array');
            $context->markImmutableBinding('object', true);
        });
        self::assertSame(['array' => true, 'object' => true], $context->immutableVars);
        self::assertSame(['object' => true], $context->immutableObjectVars);
        $fresh = new FunctionContext();
        self::assertSame([], $fresh->immutableVars);
        self::assertSame([], $fresh->immutableObjectVars);
    }
}

<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;
use TypePhp\Type;

final class FunctionContextVariableStateTest extends TestCase
{
    public function testVariantStoragePlanSurvivesAnalysisAndLaterDeclarations(): void
    {
        $context = new FunctionContext();
        $context->planVariantStorage('value');
        self::assertArrayNotHasKey('value', $context->localVars);
        $context->addLocalVar('value', Type::OBJECT);
        self::assertSame(Type::VAR, $context->localVars['value']);

        $context->withAnalysisTemporaries(function () use ($context): void {
            $context->setInferredLocalType('value', Type::INT);
            self::assertSame(Type::INT, $context->localVars['value']);
            self::assertSame(Type::VAR, $context->varTypeDegradations['value']);
        });
        $context->addLocalVar('value', Type::FLOAT);
        self::assertSame(Type::VAR, $context->localVars['value']);
    }

    public function testArgumentDegradationKeepsTheSignatureAndClearsOnlyLocalObjectFacts(): void
    {
        $context = new FunctionContext();
        $context->arguments['object'] = Type::OBJECT;
        $context->addLocalVar('object', Type::OBJECT);
        $context->declareObjectClass('object', 'Contract');
        $context->bindObjectDispatchClass('object', 'Implementation');
        $context->recordStableObjectClass('object', 'Implementation', true);
        $context->markNativeObjectNonNull('object');
        $context->bindObjectDispatchClass('other', 'Other');

        $context->degradeArgumentStorageToVariant('object');

        self::assertSame(Type::OBJECT, $context->arguments['object']);
        self::assertSame(Type::VAR, $context->localVars['object']);
        self::assertSame(Type::VAR, $context->varTypeDegradations['object']);
        self::assertArrayNotHasKey('object', $context->declaredObjects);
        self::assertArrayNotHasKey('object', $context->objects);
        self::assertArrayNotHasKey('object', $context->stableObjects);
        self::assertArrayNotHasKey('object', $context->exactObjects);
        self::assertArrayNotHasKey('object', $context->nonNullNativeObjects);
        self::assertSame('Other', $context->objects['other']);
    }

    public function testProofInvalidationKeepsDeclaredClassAndNativePointerMetadata(): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('object', 'NativeThing *');
        $context->declareObjectClass('object', 'NativeContract');
        $context->bindNativeObjectClass('object', '\\NativeThing');
        $context->recordStableObjectClass('object', 'NativeThing', true);
        $context->markNativeObjectNonNull('object');
        $context->invalidateObjectTypeProofs('object');

        self::assertSame('NativeThing *', $context->localVars['object']);
        self::assertSame('NativeContract', $context->declaredObjects['object']);
        self::assertSame('NativeThing', $context->nativeObjects['object']);
        self::assertSame('NativeThing', $context->objects['object']);
        self::assertSame([], $context->stableObjects);
        self::assertSame([], $context->exactObjects);
        self::assertSame([], $context->nonNullNativeObjects);
    }

    public function testNativeRebindingInvalidatesOnlyTheNonNullProof(): void
    {
        $context = new FunctionContext();
        $context->bindNativeObjectClass('object', 'NativeThing');
        $context->recordStableObjectClass('object', 'NativeThing', true);
        $context->markNativeObjectNonNull('object');
        $context->forgetNativeObjectNonNull('object');

        self::assertArrayNotHasKey('object', $context->nonNullNativeObjects);
        self::assertSame('NativeThing', $context->nativeObjects['object']);
        self::assertSame('NativeThing', $context->objects['object']);
        self::assertSame('NativeThing', $context->stableObjects['object']);
        self::assertSame('NativeThing', $context->exactObjects['object']);
    }

    public function testSuppressingDispatchKeepsConstraintsAndNativeContainerElementType(): void
    {
        $context = new FunctionContext();
        $context->declareObjectClass('element', 'Contract');
        $context->bindNativeObjectClass('element', 'NativeElement');
        $context->forgetObjectDispatchClass('element');

        self::assertSame('Contract', $context->declaredObjects['element']);
        self::assertSame('NativeElement', $context->nativeObjects['element']);
        self::assertArrayNotHasKey('element', $context->objects);
    }

    public function testExactClassProofCannotOutliveItsStableClass(): void
    {
        $context = new FunctionContext();
        $context->recordStableObjectClass('object', 'Concrete', true);
        $context->recordStableObjectClass('object', 'Contract', false);
        self::assertSame('Contract', $context->stableObjects['object']);
        self::assertArrayNotHasKey('object', $context->exactObjects);
    }

    public function testReferenceCaptureReservesOnlyMissingLocalBindings(): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('existing', Type::VAR);
        $context->staticVars['static'] = Type::VAR;
        foreach (['new', 'existing', 'static'] as $name) {
            $context->registerReferenceCaptureLocal($name);
        }
        self::assertSame(Type::REF, $context->localVars['new']);
        self::assertSame(Type::VAR, $context->localVars['existing']);
        self::assertArrayNotHasKey('static', $context->localVars);
    }

    public function testInitializerLocalsAreDiscardedWithoutChangingExistingBindings(): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('outer', Type::INT);
        $context->addLocalVar('temporary', Type::ARRAY);
        $context->discardInitializerLocals(1);
        self::assertSame(['outer' => Type::INT], $context->localVars);
    }

    public function testArgumentDegradationRejectsNativePointerStorageWithoutChangingIt(): void
    {
        $context = new FunctionContext();
        $context->arguments['object'] = 'NativeThing *';
        $context->addLocalVar('object', 'NativeThing *');
        $context->bindNativeObjectClass('object', 'NativeThing');
        try {
            $context->degradeArgumentStorageToVariant('object');
            self::fail('Native pointer storage must not become a Variant');
        } catch (\LogicException) {
            self::assertSame('NativeThing *', $context->localVars['object']);
            self::assertSame('NativeThing', $context->nativeObjects['object']);
            self::assertSame([], $context->varTypeDegradations);
        }
    }
}

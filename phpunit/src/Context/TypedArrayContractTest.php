<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;
use TypePhp\Type;

final class TypedArrayContractTest extends TestCase
{
    private function definition(): array
    {
        return ['kind' => 'list', 'keyType' => Type::INT, 'type' => Type::INT, 'class' => null];
    }

    public function testRepeatedRegistrationKeepsTheContractAndStorage(): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('values', Type::ARRAY);
        $definition = $this->definition();
        $context->registerTypedArrayContract('values', $definition);
        $context->registerTypedArrayContract('values', $definition);
        $definition['type'] = Type::FLOAT;
        self::assertSame($this->definition(), $context->getTypedArrayContract('values'));
        self::assertSame(['values' => Type::ARRAY], $context->localVars);
        self::assertSame([], $context->typedRefBindings);
    }

    #[DataProvider('invalidStorage')]
    public function testInvalidStorageCannotAcquireAContract(?string $type, string $restriction): void
    {
        $context = new FunctionContext();
        if ($type !== null) {
            $context->addLocalVar('values', $type);
        }
        if ($restriction === 'degraded') {
            $context->planVariantStorage('values');
        } elseif ($restriction === 'global') {
            $context->globalVars['values'] = Type::ARRAY;
        } elseif ($restriction === 'static') {
            $context->staticVars['values'] = Type::ARRAY;
        }
        $locals = $context->localVars;
        try {
            $context->registerTypedArrayContract('values', $this->definition());
            self::fail('An invalid storage binding must not acquire a contract');
        } catch (\LogicException $error) {
            self::assertStringContainsString('fixed local array storage', $error->getMessage());
        }
        self::assertNull($context->getTypedArrayContract('values'));
        self::assertSame($locals, $context->localVars);
    }

    public static function invalidStorage(): array
    {
        return [
            'missing' => [null, ''],
            'variant' => [Type::VAR, ''],
            'reference wrapper' => [Type::REF, ''],
            'scalar' => [Type::INT, ''],
            'degraded' => [Type::ARRAY, 'degraded'],
            'global' => [Type::ARRAY, 'global'],
            'static' => [Type::ARRAY, 'static'],
        ];
    }

    #[DataProvider('changedContracts')]
    public function testDifferentContractsCannotOverwriteAnExistingBinding(string $field, mixed $value): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('values', Type::ARRAY);
        $context->registerTypedArrayContract('values', $this->definition());
        $changed = $this->definition();
        $changed[$field] = $value;
        try {
            $context->registerTypedArrayContract('values', $changed);
            self::fail('A binding must retain its contract');
        } catch (\LogicException $error) {
            self::assertStringContainsString('identical list/dict contract', $error->getMessage());
        }
        self::assertSame($this->definition(), $context->getTypedArrayContract('values'));
    }

    public static function changedContracts(): array
    {
        return [
            'list versus dict' => ['kind', 'dict'],
            'key type' => ['keyType', Type::STR],
            'value type' => ['type', Type::FLOAT],
            'class' => ['class', 'DifferentClass'],
        ];
    }

    public function testAliasChainsRetainTheRootContractWhileValueCapturesOwnTheirStorage(): void
    {
        $source = new FunctionContext();
        $source->addLocalVar('root', Type::ARRAY);
        $source->registerTypedArrayContract('root', $this->definition());
        $source->registerTypedRefLocal('first', 'root', Type::ARRAY_REF);
        $source->registerTypedArrayContract('first', $this->definition());
        $source->registerTypedRefLocal('second', 'first', Type::ARRAY_REF);
        $source->registerTypedArrayContract('second', $this->definition());
        self::assertSame('root', $source->getTypedRefRoot('second'));
        self::assertSame($source->getTypedArrayContract('root'), $source->getTypedArrayContract('second'));

        $target = new FunctionContext();
        $target->addLocalVar('second', Type::ARRAY);
        $target->importTypedArrayContract($source, 'second');
        self::assertSame($this->definition(), $target->getTypedArrayContract('second'));
        self::assertSame(['second' => Type::ARRAY], $target->localVars);
        self::assertSame('second', $target->getTypedRefRoot('second'));
        self::assertSame([], $target->typedRefBindings);
        self::assertNull($target->getTypedArrayContract('root'));
    }

    public function testAliasCannotAcquireAContractDifferentFromItsRoot(): void
    {
        $context = new FunctionContext();
        $context->addLocalVar('root', Type::ARRAY);
        $context->registerTypedArrayContract('root', $this->definition());
        $context->registerTypedRefLocal('alias', 'root', Type::ARRAY_REF);
        $changed = $this->definition();
        $changed['type'] = Type::FLOAT;
        try {
            $context->registerTypedArrayContract('alias', $changed);
            self::fail('An alias must share its root contract');
        } catch (\LogicException $error) {
            self::assertStringContainsString('root contract', $error->getMessage());
        }
        self::assertNull($context->getTypedArrayContract('alias'));
        self::assertSame($this->definition(), $context->getTypedArrayContract('root'));
    }

    public function testReferenceParametersKeepTheirAbiAndContractAcrossAnalysis(): void
    {
        $context = new FunctionContext();
        $context->arguments['values'] = Type::ARRAY_REF;
        $context->addLocalVar('values', Type::ARRAY_REF);
        $context->registerTypedArrayContract('values', $this->definition());
        $context->withAnalysisTemporaries(function () use ($context): void {
            $context->setInferredLocalType('values', Type::VAR);
        });
        self::assertSame(['values' => Type::ARRAY_REF], $context->arguments);
        self::assertSame(['values' => Type::ARRAY_REF], $context->localVars);
        self::assertSame($this->definition(), $context->getTypedArrayContract('values'));
    }

    public function testMissingSourceCannotCreateOrEraseAContract(): void
    {
        $source = new FunctionContext();
        $target = new FunctionContext();
        $target->importTypedArrayContract($source, 'missing');
        self::assertNull($target->getTypedArrayContract('missing'));
        self::assertSame([], $target->localVars);
        $target->addLocalVar('existing', Type::ARRAY);
        $target->registerTypedArrayContract('existing', $this->definition());
        $target->importTypedArrayContract($source, 'existing');
        self::assertSame($this->definition(), $target->getTypedArrayContract('existing'));
    }
}

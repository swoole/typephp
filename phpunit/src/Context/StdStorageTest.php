<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;
use TypePhp\Type;

final class StdStorageTest extends TestCase
{
    private function info(string $kind): array
    {
        return ['kind' => $kind, 'type' => Type::INT, 'class' => '', 'decl' => 'Container<' . $kind . '>', 'typeId' => 17];
    }

    public static function kinds(): array
    {
        return [
            'array' => ['array', Type::STD_ARRAY],
            'vector' => ['vector', Type::STD_VECTOR],
            'map' => ['map', Type::STD_MAP],
            'ordered map' => ['ordered_map', Type::STD_ORDERED_MAP],
        ];
    }

    #[DataProvider('kinds')]
    public function testRegistrationCommitsMatchingStorageAndMetadata(string $kind, string $type): void
    {
        $context = new FunctionContext();
        $context->registerStdStorage('values', $this->info($kind));
        self::assertSame(['values' => $type], $context->localVars);
        self::assertSame($this->info($kind), $context->getStdStorageInfo('values'));
        self::assertNull($context->getStdStorageInfo('missing'));
    }

    #[DataProvider('kinds')]
    public function testParameterStorageRetainsTheBoxAbiAndMetadataAcrossAnalysis(string $kind, string $type): void
    {
        $context = new FunctionContext();
        $context->arguments['values'] = Type::VAR;
        $context->addLocalVar('values', Type::VAR);
        $info = $this->info($kind);
        $info['parameter'] = true;
        $context->registerStdStorage('values', $info);
        $context->withAnalysisTemporaries(function () use ($context): void {
            $context->setInferredLocalType('values', Type::VAR);
        });
        self::assertSame(['values' => Type::VAR], $context->arguments);
        self::assertSame(['values' => $type], $context->localVars);
        self::assertSame($info, $context->getStdStorageInfo('values'));
    }

    public function testRegistrationRejectsUnknownKindsAndConflictingStorageWithoutPartialChanges(): void
    {
        $context = new FunctionContext();
        foreach (['unknown', 'degraded', 'scalar', 'typed array'] as $invalid) {
            if ($invalid === 'degraded') {
                $context->planVariantStorage($invalid);
            } elseif ($invalid === 'scalar') {
                $context->addLocalVar($invalid, Type::INT);
            } elseif ($invalid === 'typed array') {
                $context->addLocalVar($invalid, Type::ARRAY);
                $context->registerTypedArrayContract($invalid, ['kind' => 'list', 'keyType' => Type::INT, 'type' => Type::INT, 'class' => null]);
            }
            $locals = $context->localVars;
            try {
                $context->registerStdStorage($invalid, $this->info($invalid === 'unknown' ? $invalid : 'vector'));
                self::fail('Invalid storage must not acquire std metadata');
            } catch (\LogicException) {
                self::assertNull($context->getStdStorageInfo($invalid));
                self::assertSame($locals, $context->localVars);
            }
        }
    }

    public function testBoxBindingPreservesTheRegisteredTypeIdAndDeclaration(): void
    {
        $context = new FunctionContext();
        $info = $this->info('vector');
        $context->registerStdStorage('values', $info);
        $context->bindStdBoxSource('values', 'source_box');
        self::assertSame($info + ['boxExpr' => 'source_box'], $context->getStdStorageInfo('values'));
        self::assertSame(['values' => Type::STD_VECTOR], $context->localVars);
        try {
            $context->bindStdBoxSource('missing', 'source_box');
            self::fail('A Box source requires existing container metadata');
        } catch (\LogicException) {
            self::assertNull($context->getStdStorageInfo('missing'));
        }
    }

    public function testNestedIterationFailureKeepsTheOuterContainerProtected(): void
    {
        $context = new FunctionContext();
        $context->registerStdStorage('values', $this->info('vector'));
        $context->registerStdStorage('other', $this->info('map'));
        $failure = new \RuntimeException('nested lowering failed');
        $result = $context->withStdContainerIteration('values', function () use ($context, $failure): int {
            self::assertTrue($context->isStdContainerIterating('values'));
            self::assertFalse($context->isStdContainerIterating('other'));
            try {
                $context->withStdContainerIteration('values', function () use ($context, $failure): void {
                    $context->withStdContainerIteration('other', function () use ($context, $failure): void {
                        self::assertTrue($context->isStdContainerIterating('values'));
                        self::assertTrue($context->isStdContainerIterating('other'));
                        throw $failure;
                    });
                });
                self::fail('Expected nested lowering to fail');
            } catch (\RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertTrue($context->isStdContainerIterating('values'));
            self::assertFalse($context->isStdContainerIterating('other'));
            return 42;
        });
        self::assertSame(42, $result);
        self::assertFalse($context->isStdContainerIterating('values'));
        self::assertFalse($context->isStdContainerIterating('other'));
        self::assertSame($this->info('vector'), $context->getStdStorageInfo('values'));
    }

    public function testFixedArraysDoNotAcquireStructuralIterationRestrictions(): void
    {
        $context = new FunctionContext();
        $context->registerStdStorage('fixed', $this->info('array'));
        $context->withStdContainerIteration('fixed', function () use ($context): void {
            self::assertFalse($context->isStdContainerIterating('fixed'));
        });
        self::assertFalse($context->isStdContainerIterating('fixed'));
        try {
            $context->withStdContainerIteration('missing', static function (): void {});
            self::fail('An unknown binding cannot acquire iteration state');
        } catch (\LogicException) {
            self::assertFalse($context->isStdContainerIterating('missing'));
        }
    }
}

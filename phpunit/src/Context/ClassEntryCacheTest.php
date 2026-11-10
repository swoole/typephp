<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypePhp\Context\FunctionContext;
use TypePhp\Type;

final class ClassEntryCacheTest extends TestCase
{
    public function testEntriesAndWrappersAllocateDistinctNamesOnlyOnFirstUse(): void
    {
        $context = new FunctionContext();
        $allocate = fn() => 'tmp_var_' . $context->tmpVarIndex++;
        $entry = $context->getOrCreateLocalClassEntry('Example', $allocate);
        self::assertSame([], $context->localVars);
        $wrapper = $context->getOrCreateClassWrapper('Example', $allocate);

        $unexpected = static function (): string {
            throw new \LogicException('A cache hit must not allocate another local');
        };
        self::assertSame($entry, $context->getOrCreateLocalClassEntry('Example', $unexpected));
        self::assertSame($wrapper, $context->getOrCreateClassWrapper('Example', $unexpected));
        self::assertNotSame($entry, $wrapper);
        self::assertSame(2, $context->tmpVarIndex);
        self::assertSame([$wrapper => Type::OBJECT], $context->localVars);

        $context->getOrCreateLocalClassEntry('Other', $allocate);
        $context->getOrCreateClassWrapper('Other', $allocate);
        self::assertSame(['Example', 'Other'], array_keys($context->getLocalClassEntries()));
        self::assertSame(['Example', 'Other'], array_keys($context->getClassWrappers()));
    }

    #[DataProvider('allocators')]
    public function testFailedAllocationLeavesNoCacheEntryOrStorage(string $method, bool $objectStorage): void
    {
        $context = new FunctionContext();
        $failure = new \RuntimeException('allocation failed');
        try {
            $context->{$method}('Example', function () use ($context, $failure): string {
                $context->tmpVarIndex++;
                throw $failure;
            });
            self::fail('Expected the allocator failure');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame([], $context->getLocalClassEntries());
        self::assertSame([], $context->getClassWrappers());
        self::assertSame([], $context->localVars);
        self::assertSame(1, $context->tmpVarIndex);

        $name = $context->{$method}('Example', fn() => 'tmp_var_' . $context->tmpVarIndex++);
        self::assertSame('tmp_var_1', $name);
        self::assertSame($objectStorage ? [$name => Type::OBJECT] : [], $context->localVars);
    }

    public static function allocators(): array
    {
        return [
            'class entry' => ['getOrCreateLocalClassEntry', false],
            'Object carrier' => ['getOrCreateClassWrapper', true],
        ];
    }

    public function testContextsAndDeclarationSnapshotsAreIsolated(): void
    {
        $outer = new FunctionContext();
        $outer->getOrCreateLocalClassEntry('Example', static fn() => 'outer_entry');
        $outer->getOrCreateClassWrapper('Example', static fn() => 'outer_wrapper');
        $entries = $outer->getLocalClassEntries();
        $wrappers = $outer->getClassWrappers();
        $entries['Example'] = 'changed';
        unset($wrappers['Example']);
        self::assertSame(['Example' => 'outer_entry'], $outer->getLocalClassEntries());
        self::assertSame(['Example' => 'outer_wrapper'], $outer->getClassWrappers());

        $inner = new FunctionContext();
        self::assertSame([], $inner->getLocalClassEntries());
        self::assertSame([], $inner->getClassWrappers());
        $inner->getOrCreateClassWrapper('Example', static fn() => 'inner_wrapper');
        self::assertSame(['inner_wrapper' => Type::OBJECT], $inner->localVars);
        self::assertSame(['outer_wrapper' => Type::OBJECT], $outer->localVars);
    }

    public function testAnalysisPreservesEntryCarriersAndDiscardsSpeculativeEntryPointers(): void
    {
        $context = new FunctionContext();
        $context->getOrCreateClassWrapper('Example', static fn() => 'existing_wrapper');
        $context->withAnalysisTemporaries(function () use ($context): void {
            $context->getOrCreateLocalClassEntry('Example', static fn() => 'analysis_entry');
            $context->addLocalVar('analysis_local', Type::VAR);
        });

        self::assertSame([], $context->getLocalClassEntries());
        self::assertSame(['Example' => 'existing_wrapper'], $context->getClassWrappers());
        self::assertSame(['existing_wrapper' => Type::OBJECT], $context->localVars);
    }
}

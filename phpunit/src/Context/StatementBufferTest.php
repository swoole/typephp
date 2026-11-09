<?php

namespace TypePhp\Tests\Context;

use PHPUnit\Framework\TestCase;
use TypePhp\Context\StatementBuffer;

final class StatementBufferTest extends TestCase
{
    public function testTakingOneQueuePreservesTheOther(): void
    {
        $buffer = new StatementBuffer();
        self::assertFalse($buffer->hasPending());
        $buffer->addBefore('prepare();');
        $buffer->addAfter('release();');
        self::assertSame(['prepare();'], $buffer->takeBefore());
        self::assertFalse($buffer->hasBefore());
        self::assertTrue($buffer->hasPending());
        self::assertSame(1, $buffer->afterCount());
        self::assertSame(['release();'], $buffer->takeAfter());
        self::assertFalse($buffer->hasPending());
        self::assertSame([], $buffer->takeBefore());
        self::assertSame([], $buffer->takeAfter());
    }

    public function testPreludeSnapshotCannotMutateTheBuffer(): void
    {
        $buffer = new StatementBuffer();
        $buffer->addBefore('original();');
        $snapshot = $buffer->peekBefore();
        $snapshot[] = 'external();';
        self::assertSame(['original();'], $buffer->peekBefore());
    }

    public function testCapturePreservesOuterStatementsAndEvaluationOrder(): void
    {
        $buffer = new StatementBuffer();
        $buffer->addBefore('outer_prepare();');
        $buffer->addAfter('outer_release();');

        $capture = $buffer->capture(function () use ($buffer): string {
            $buffer->addBefore('receiver();');
            $buffer->addBefore('argument();');
            $buffer->addAfter('argument_release();');
            $buffer->addAfter('receiver_release();');
            return 'call_result';
        });

        self::assertSame([
            'call_result',
            ['receiver();', 'argument();'],
            ['argument_release();', 'receiver_release();'],
        ], $capture);
        self::assertSame(['outer_prepare();'], $buffer->takeBefore());
        self::assertSame(['outer_release();'], $buffer->takeAfter());
    }

    public function testNestedCaptureDoesNotLeakIntoTheOuterCapture(): void
    {
        $buffer = new StatementBuffer();
        $buffer->addBefore('enclosing();');
        $buffer->addAfter('enclosing_cleanup();');
        $outer = $buffer->capture(function () use ($buffer): array {
            $buffer->addBefore('left();');
            $buffer->addAfter('left_cleanup();');
            $inner = $buffer->capture(function () use ($buffer): int {
                $buffer->addBefore('right();');
                $buffer->addAfter('right_cleanup();');
                return 42;
            });
            $buffer->addBefore('tail();');
            return $inner;
        });

        self::assertSame([
            [42, ['right();'], ['right_cleanup();']],
            ['left();', 'tail();'],
            ['left_cleanup();'],
        ], $outer);
        self::assertSame(['enclosing();'], $buffer->takeBefore());
        self::assertSame(['enclosing_cleanup();'], $buffer->takeAfter());
    }

    public function testFailedCaptureRestoresBothQueuesAndAllowsAnotherCapture(): void
    {
        $buffer = new StatementBuffer();
        $buffer->addBefore('outer();');
        $buffer->addAfter('outer_cleanup();');
        $failure = new \RuntimeException('lowering failed');
        try {
            $buffer->capture(function () use ($buffer, $failure): void {
                $buffer->addBefore('partial();');
                $buffer->addAfter('partial_cleanup();');
                throw $failure;
            });
            self::fail('Expected the lowering failure');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame([null, [], []], $buffer->capture(static fn() => null));
        self::assertSame(['outer();'], $buffer->takeBefore());
        self::assertSame(['outer_cleanup();'], $buffer->takeAfter());
    }

    public function testInnerFailureKeepsTheOuterCaptureUsable(): void
    {
        $buffer = new StatementBuffer();
        $capture = $buffer->capture(function () use ($buffer): bool {
            $buffer->addBefore('outer();');
            try {
                $buffer->capture(function () use ($buffer): void {
                    $buffer->addBefore('discarded();');
                    $buffer->addAfter('discarded_cleanup();');
                    throw new \RuntimeException('inner failure');
                });
            } catch (\RuntimeException) {
                $buffer->addAfter('outer_cleanup();');
            }
            return true;
        });

        self::assertSame([true, ['outer();'], ['outer_cleanup();']], $capture);
        self::assertFalse($buffer->hasPending());
    }

    public function testCallCleanupIsInsertedWithinItsCapturedRegionBeforeRefCommit(): void
    {
        $buffer = new StatementBuffer();
        $buffer->addAfter('enclosing_cleanup();');
        $capture = $buffer->capture(function () use ($buffer): string {
            $baseline = $buffer->afterCount();
            $buffer->addAfter('ref_commit();');
            $buffer->insertAfter($baseline, ['args_clear();', 'variadic_clear();']);
            return 'call';
        });

        self::assertSame(['call', [], ['args_clear();', 'variadic_clear();', 'ref_commit();']], $capture);
        self::assertSame(['enclosing_cleanup();'], $buffer->takeAfter());
    }

    public function testCleanupInsertionRejectsOffsetsOutsideTheBuffer(): void
    {
        $buffer = new StatementBuffer();
        foreach ([-1, 1] as $offset) {
            try {
                $buffer->insertAfter($offset, ['cleanup();']);
                self::fail('Expected an invalid insertion offset');
            } catch (\OutOfBoundsException) {
                self::assertFalse($buffer->hasPending());
            }
        }
    }

    public function testClearingDiscardsBothQueues(): void
    {
        $buffer = new StatementBuffer();
        $buffer->addBefore('prepare();');
        $buffer->addAfter('release();');
        $buffer->clear();
        self::assertFalse($buffer->hasPending());
        self::assertSame([], $buffer->takeBefore());
        self::assertSame([], $buffer->takeAfter());
    }
}

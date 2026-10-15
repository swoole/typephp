<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

function testUniformIntMatch(int $x): int
{
    return match ($x) {
        1 => 10,
        2 => 20,
        default => 30,
    };
}

function testUniformStringMatch(int $x): string
{
    return match ($x) {
        1 => 'first',
        2 => 'second',
        default => 'other',
    };
}

function testMatchWithThrow(int $x): int
{
    return match ($x) {
        1 => 10,
        2 => 20,
        default => throw new \RuntimeException('Invalid choice'),
    };
}

function testMatchNoDefault(int $x): int
{
    return match ($x) {
        1 => 100,
        2 => 200,
    };
}

function testMixedTypeMatch(int $x): mixed
{
    return match ($x) {
        1 => 10,
        default => 'other',
    };
}

function testMatchLocalVariableAssignment(int $x): void
{
    $result = match ($x) {
        1 => 10,
        default => 20,
    };
    var_dump($result);
}

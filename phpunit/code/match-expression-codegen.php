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

class MatchCodegenHolder
{
    public int $number = 42;
}

function testMatchArmPropertyRead(int $x, MatchCodegenHolder $obj): int
{
    return match ($x) {
        1 => $obj->number,
        default => 0,
    };
}

function testMatchArmTernaryPropertyRead(int $x, MatchCodegenHolder $obj, bool $flag): int
{
    return match ($x) {
        1 => ($flag ? $obj->number : 0),
        default => 0,
    };
}

function testMatchBigInt(int $flag)
{
    $value = match ($flag) {
        1 => std::bigInt('123'),
        default => std::bigInt('456'),
    };
    return $value;
}

function testMatchDecimal(int $flag)
{
    $value = match ($flag) {
        1 => std::decimal('12.5'),
        default => std::decimal('45.5'),
    };
    return $value;
}

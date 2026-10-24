<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

use varint_types;

function testVarIntMatchMixed(int $x, int $y): mixed
{
    return match ($x) {
        1 => $y + 1,
        default => 0,
    };
}

function testVarIntMatchInt(int $x, int $y): int
{
    return match ($x) {
        1 => $y + 1,
        default => 0,
    };
}

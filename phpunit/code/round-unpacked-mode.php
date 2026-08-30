<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

function main(): void
{
    $all = [2.5, 0, PHP_ROUND_HALF_DOWN];
    $tail = [0, PHP_ROUND_HALF_DOWN];

    var_dump(round(...$all));
    var_dump(round(2.5, ...$tail));
}

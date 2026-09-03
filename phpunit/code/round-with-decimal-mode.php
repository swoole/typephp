<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

function main(): void
{
    $decimal = std::decimal('2.5');

    var_dump(round($decimal));
    var_dump(round($decimal, 2));
    var_dump(round($decimal, 0, PHP_ROUND_HALF_DOWN));
}

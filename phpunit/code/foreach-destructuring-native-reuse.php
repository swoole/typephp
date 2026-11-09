<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

/**
 * This file is part of TypePHP(AOT).
 *
 * @see     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */
function foreachDestructuringNativeReuse(array $rows): void
{
    $integer = 0;
    $floating = 0.0;
    $boolean = false;
    $string = '';
    foreach ($rows as [$integer, $floating, $boolean, $string]) {
        var_dump($integer, $floating, $boolean, $string);
    }
    foreach ($rows as ['nested' => [$integer]]) {
        var_dump($integer);
    }
}

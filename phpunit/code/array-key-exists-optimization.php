<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace KeyExistsOptimization;

function check(array $array, int $integer, string $string, mixed $mixed, bool $boolean, float $float): void
{
    array_key_exists($integer, $array);
    array_key_exists($string, $array);
    array_key_exists($mixed, $array);
    array_key_exists($boolean, $array);
    array_key_exists($float, $array);
    array_key_exists(null, $array);
    array_key_exists([], $array);
    array_key_exists(new \stdClass(), $array);
    key_exists($mixed, $array);
    $array->keyExists($mixed);
}

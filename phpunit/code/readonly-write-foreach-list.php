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
class ReadonlyForeachListWrite
{
    public readonly int $value;

    public function __construct()
    {
        foreach ([[1]] as [$this->value]) {
        }
    }
}

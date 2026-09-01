<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

function getParentClassInternalLiteral(): void
{
    var_dump(get_parent_class('RuntimeException'));
    var_dump(get_parent_class('Exception'));
}

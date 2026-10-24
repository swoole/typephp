<?php
namespace Incremental;
abstract class Base
{
    abstract public function amount(int $value = 5): int;
}
function answer(int $value = 42, string ...$labels): int { return $value; }

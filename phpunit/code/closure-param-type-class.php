<?php
class MyClass
{
    public function method(): int
    {
        $fn = fn(int $x) => $x + 1;
        return $fn(42);
    }
}

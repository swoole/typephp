--TEST--
Closure parameters accept function and method return values
--FILE--
<?php
declare(strict_types=1);

function narrow_get_int(): int
{
    return 5;
}

class NarrowCalc
{
    public function value(): int
    {
        return 9;
    }
}

function main(): void
{
    $inc = fn($x) => $x + 1;

    var_dump($inc(strlen('abcd')));
    var_dump($inc(narrow_get_int()));

    $calc = new NarrowCalc();
    var_dump($inc($calc->value()));

    // Declared parameter with a call result.
    $typed = fn(int $x) => $x + 1;
    var_dump($typed(narrow_get_int()));
}
?>
--EXPECT--
int(5)
int(6)
int(10)
int(6)

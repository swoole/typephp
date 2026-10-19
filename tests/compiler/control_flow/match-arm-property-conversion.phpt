--TEST--
Match expression arm property read is converted at typed lambda return boundary
--FILE--
<?php
class Holder
{
    public int $number = 42;
}

function example(int $x, Holder $obj): int
{
    return match ($x) {
        1 => $obj->number,
        default => 0,
    };
}

function main(): void
{
    $obj = new Holder();
    var_dump(example(1, $obj));
    var_dump(example(2, $obj));
}
?>
--EXPECT--
int(42)
int(0)

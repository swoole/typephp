--TEST--
Closure parameters accept typed property, static property and array element reads
--FILE--
<?php
declare(strict_types=1);

class NarrowBox
{
    public int $value = 42;
}

class NarrowStaticBox
{
    public static int $value = 7;
}

function main(): void
{
    $box = new NarrowBox();

    // Untyped parameter: the property read stays boxed as php::Var.
    $untyped = fn($x) => $x + 1;
    var_dump($untyped($box->value));

    // Typed parameter: the call site must convert, not assume a native ABI.
    $typed = fn(int $x) => $x + 1;
    var_dump($typed($box->value));

    // Static property read.
    $static = fn($x) => $x + 1;
    var_dump($static(NarrowStaticBox::$value));

    // Nullsafe property read.
    $nullsafe = fn($x) => $x + 1;
    var_dump($nullsafe($box?->value));

    // Array element read.
    $values = [10, 20];
    $element = fn($x) => $x + 1;
    var_dump($element($values[0]));
}
?>
--EXPECT--
int(43)
int(43)
int(8)
int(43)
int(11)

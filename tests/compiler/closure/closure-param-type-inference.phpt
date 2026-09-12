--TEST--
Closure parameter type inference from call-site literals
--FILE--
<?php

function main(): void
{
    $fn1 = fn($x) => $x + 1;
    var_dump($fn1(42));

    $fn2 = fn($x) => $x * 2.0;
    var_dump($fn2(3.14));

    $fn3 = fn($x) => !$x;
    var_dump($fn3(true));

    $fn4 = fn($x) => count($x);
    var_dump($fn4([1, 2]));

    $fn5 = fn(int $x) => $x + 1;
    var_dump($fn5(42));

    $fn6 = fn($x) => $x + 1;
    var_dump($fn6(42));
    var_dump($fn6(3.14));
}
?>
--EXPECT--
int(43)
float(6.28)
bool(false)
int(2)
int(43)
int(43)
float(4.140000000000001)

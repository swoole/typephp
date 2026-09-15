--TEST--
Closure parameters accept array literals and array variables
--FILE--
<?php
declare(strict_types=1);

function main(): void
{
    $count = fn($x) => count($x);

    // An array literal is materialized into a temporary; the parameter must
    // match the C++ type actually produced at the call boundary.
    var_dump($count([1, 2]));

    // An array variable keeps its php::Array storage.
    $values = [1, 2, 3];
    var_dump($count($values));

    // Nested arrays.
    var_dump($count([[1], [2], [3], [4]]));
}
?>
--EXPECT--
int(2)
int(3)
int(4)

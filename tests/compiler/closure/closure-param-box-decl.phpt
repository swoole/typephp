--TEST--
Box-typed Closure parameters accept valid Box arguments
--FILE--
<?php
declare(strict_types=1);

function main(): void
{
    // A Box value still has no faithful runtime representation, so the call
    // boundary must not reject an argument that satisfies the declaration.
    // The Zend Closure path performs no such check either; narrowing must not
    // change which programs are accepted.
    $decimal = fn(decimal $x) => $x;
    try {
        $decimal(std::decimal(12345));
        echo "decimal: accepted\n";
    } catch (TypeError $e) {
        echo "decimal: TypeError\n";
    }

    $bigint = fn(bigint $x) => $x;
    try {
        $bigint(std::bigInt(42));
        echo "bigint: accepted\n";
    } catch (TypeError $e) {
        echo "bigint: TypeError\n";
    }

    $bigfloat = fn(bigfloat $x) => $x;
    try {
        $bigfloat(std::bigFloat(2.5));
        echo "bigfloat: accepted\n";
    } catch (TypeError $e) {
        echo "bigfloat: TypeError\n";
    }
}
?>
--EXPECT--
decimal: accepted
bigint: accepted
bigfloat: accepted

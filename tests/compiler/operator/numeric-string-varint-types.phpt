--TEST--
Integer-syntax numeric strings follow varint_types PHP semantics
--FILE--
<?php
use varint_types;

function main(): void
{
    var_dump("9223372036854775807" * 2);
    var_dump("9223372036854775807" + 0);
    var_dump("5" / 2);
    var_dump("10" / 2);
    $n = 2;
    var_dump("5" / $n);
    try { var_dump("5" / "0"); } catch (DivisionByZeroError $e) { echo "DivisionByZeroError\n"; }
}
?>
--EXPECT--
float(1.8446744073709552E+19)
int(9223372036854775807)
float(2.5)
int(5)
float(2.5)
DivisionByZeroError

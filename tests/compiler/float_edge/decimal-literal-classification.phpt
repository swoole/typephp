--TEST--
Auto-Decimal literal classification: significant digits, hex, float mixing
--FILE--
<?php
declare(strict_types=1);

function main(): void
{
    // 15 significant digits (exponent digits carry no precision): float.
    var_dump(is_float(1.23456789012345e300));
    // Trailing mantissa zeros carry no precision: float.
    var_dump(is_float(999999999999999.0));
    // 16 digits, but the double reproduces the value exactly: float.
    var_dump(is_float(2.220446049250313E-16));
    // Hex folds to its exact numeric value like Zend.
    var_dump(0x123456789E1234567);
    // var_export round-trip comparisons stay plain float comparisons.
    var_dump(0.1 + 0.2 == 0.30000000000000004);
    $f = 0.1;
    var_dump($f + 0.2 == 0.30000000000000004);
    // 21 significant digits still promote to Decimal (documented feature).
    var_dump(is_float(3.14159265358979323846));
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
float(2.098829548031543E+19)
bool(true)
bool(true)
bool(false)

--TEST--
abs edge cases preserve PHP_INT_MIN's float result on static integer paths
--FILE--
<?php
function typed_abs(int $value): int|float
{
    return abs($value);
}

function next_value(int $value, int &$calls): int
{
    $calls++;
    return $value;
}

function main(): void
{
    $minimum = PHP_INT_MIN;
    $absoluteMinimum = abs($minimum);
    var_dump($absoluteMinimum);
    var_dump(typed_abs(PHP_INT_MIN));
    var_dump(typed_abs(-42));

    $calls = 0;
    var_dump(abs(next_value(PHP_INT_MIN, $calls)));
    var_dump($calls);

    $value = std::any(PHP_INT_MIN);
    var_dump(abs($value));
    var_dump(abs(-0.0));
    var_dump(abs(0));
    var_dump(abs(-5));
    var_dump(abs(3.14));
}
?>
--EXPECT--
float(9.223372036854776E+18)
float(9.223372036854776E+18)
int(42)
float(9.223372036854776E+18)
int(1)
float(9.223372036854776E+18)
float(0)
int(0)
int(5)
float(3.14)

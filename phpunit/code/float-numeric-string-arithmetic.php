<?php
function floatNumericStringQuotient(): float
{
    return "1e2" / 4;
}
function floatNumericStringAssign(): void
{
    $x = "1e2" / 4;
    var_dump($x);
    $y = "1.5" * 2;
    var_dump($y);
}
function intNumericStringArithmetic(): void
{
    var_dump("10" / 2);
}

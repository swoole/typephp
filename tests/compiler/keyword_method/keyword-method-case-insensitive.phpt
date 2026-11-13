--TEST--
Keyword methods are matched case-insensitively like PHP method names
--FILE--
<?php

class Point
{
    public int $x = 1;
    public int $y = 2;
}

function main(): void
{
    $point = new Point();
    $array = $point->TOARRAY();
    echo count($array), ' ', $array['y'], "\n";

    $text = '42';
    $int = $text->TOINT();
    echo $int + 1, "\n";
    echo gettype($int), "\n";

    $number = 3;
    echo $number->TOSTRING() . 'x', "\n";
    echo $number->toFLOAT() / 2, "\n";
    echo var_export($number->tobool(), true), "\n";

    $point2 = null;
    echo var_export($point2?->TOARRAY(), true), "\n";
}
?>
--EXPECT--
2 2
43
integer
3x
1.5
true
NULL

--TEST--
Native int/float arithmetic methods keep the receiver type; int accepts only int arguments
--FILE--
<?php
function run(int $a, int $b, float $f): void
{
    var_dump($a->add($b), $a->sub($b), $a->mul($b), $a->div($b), $a->mod($b));
    var_dump($f->add($a), $f->sub($a), $f->mul($a), $f->div($a), $f->pow($a));
}

function main(): void
{
    run(7, 2, 2.5);
}
?>
--EXPECT--
int(9)
int(5)
int(14)
int(3)
int(1)
float(9.5)
float(-4.5)
float(17.5)
float(0.35714285714285715)
float(610.3515625)

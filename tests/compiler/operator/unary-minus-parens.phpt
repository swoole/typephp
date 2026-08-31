--TEST--
Unary minus applies to the whole operand expression
--FILE--
<?php
declare(strict_types=1);

function pick(int $a, int $b, int $c): int
{
    return -($a ? $b : $c);
}

function doubleNegate(int $a): int
{
    return - -$a;
}

function main(): void
{
    var_dump(pick(1, 2, 3));
    var_dump(pick(0, 2, 3));
    var_dump(doubleNegate(5));
    var_dump(doubleNegate(-5));
    $x = 4;
    var_dump(-($x ?: 7));
    $y = 0;
    var_dump(-($y ?: 7));
}
?>
--EXPECT--
int(-2)
int(-3)
int(5)
int(-5)
int(-4)
int(-7)

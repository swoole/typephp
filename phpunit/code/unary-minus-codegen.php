<?php

function pickNegated(int $a, int $b, int $c): int
{
    return -($a ? $b : $c);
}

function doubleNegate(int $a): int
{
    return - -$a;
}

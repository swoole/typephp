<?php

namespace FoldNs;

const PHP_INT_MAX = 5;

function shadowedFold(): int
{
    return PHP_INT_MAX + 1;
}

function globalFold(): float
{
    return \PHP_INT_MAX + 1;
}

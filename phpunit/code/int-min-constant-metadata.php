<?php

class IntMinConstants
{
    const MIN = PHP_INT_MIN;
    const MAX = PHP_INT_MAX;
    const NEGZ = -0.0;
    const PI = M_PI;

    public int $floor = PHP_INT_MIN;
}

function useIntMinDefault(int $x = PHP_INT_MIN): int
{
    return $x;
}

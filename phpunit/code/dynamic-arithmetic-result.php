<?php
function dynamicResult(array $values, int $divisor): array
{
    return [$values['x'] / $divisor];
}
function nativeIntResult(int $left, int $right): int
{
    return $left / $right;
}
function nativeFloatResult(float $left, float $right): float
{
    return $left / $right;
}

<?php

function scalarStringCast(mixed $value): string
{
    $text = (string) $value;
    return $text;
}

function scalarInterpolated(int $value): string
{
    $text = "n=$value";
    return $text;
}

function scalarInstanceCheck(object $value): bool
{
    $matches = $value instanceof stdClass;
    return $matches;
}

function scalarIssetCheck(array $values): bool
{
    $present = isset($values['key']);
    return $present;
}

function scalarEmptyCheck(mixed $value): bool
{
    $empty = empty($value);
    return $empty;
}

function scalarFixedBuiltin(string $value): int
{
    $length = strlen($value);
    $part = substr($value, 1);
    return $length + strlen($part);
}

function scalarUnionBuiltins(array $values, string $text): array
{
    $found = strpos($text, 'x');
    $last = array_pop($values);
    $sum = array_sum($values);
    $power = pow(2, 63);
    $decoded = json_decode($text);
    $results = [$found, $last, $sum, $power, $decoded];
    return $results;
}

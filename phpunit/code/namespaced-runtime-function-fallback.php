<?php

namespace {
    function globalFallback(int $value): int
    {
        return $value + 1;
    }
}

namespace RuntimeFunctionFallback {
    use function strlen as globalLength;

    function length(string $value): mixed
    {
        return strlen($value);
    }

    function differentSignature(): mixed
    {
        return strlen(value: 'a', suffix: 'b');
    }

    function unpacked(array $args): mixed
    {
        return strlen(...$args);
    }

    function firstClass(): \Closure
    {
        return strlen(...);
    }

    function globalNativeFallback(int $value): mixed
    {
        return globalFallback($value);
    }

    function explicitGlobal(string $value): int
    {
        return \strlen($value) + globalLength($value);
    }

    function append(array &$values): int
    {
        return array_push($values, 5);
    }

    function valueShadow(): mixed
    {
        return array_pop('input');
    }

    function outputReference(): string
    {
        preg_match('/a/', 'abc', $matches);
        return $matches[0];
    }
}

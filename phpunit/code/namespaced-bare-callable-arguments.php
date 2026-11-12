<?php

namespace BareFunctionFallback;

function local_callback(int $value): int
{
    return $value * 2;
}

function positional(): mixed
{
    return array_map(local_callback, [1]);
}

function named(): mixed
{
    return array_map(callback: local_callback, array: [2]);
}

function ordinary(): mixed
{
    return local_callback;
}

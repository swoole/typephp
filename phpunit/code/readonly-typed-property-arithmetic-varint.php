<?php

use varint_types;

final class ReadonlyVarIntArithmeticState
{
    public function __construct(
        public readonly int $count,
        public readonly float $width,
    ) {
    }
}

function readonlyVarIntIncrement(ReadonlyVarIntArithmeticState $state): int
{
    return $state->count + 1;
}

function readonlyVarIntWidth(ReadonlyVarIntArithmeticState $state): float
{
    return $state->width + 0.5;
}

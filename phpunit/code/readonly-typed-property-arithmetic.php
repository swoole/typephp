<?php

final class ReadonlyArithmeticState
{
    public function __construct(
        public readonly float $width,
        public readonly float $height,
        public readonly int $count,
        public readonly bool $enabled,
    ) {
    }

    public function half(): float
    {
        return $this->width / 2.0;
    }
}

function readonlyPropertyHalf(ReadonlyArithmeticState $state): float
{
    return $state->width < $state->height ? $state->width / 2.0 : $state->height / 2.0;
}

function readonlyPropertyIncrement(ReadonlyArithmeticState $state): int
{
    return $state->count + 1;
}

function readonlyPropertyBoolComparison(ReadonlyArithmeticState $state): bool
{
    return $state->enabled === true;
}

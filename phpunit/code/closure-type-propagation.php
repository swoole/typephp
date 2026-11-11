<?php

final class ClosureTypeValue
{
    public function __construct(public int $number = 7) {}
    public function value(): int { return $this->number; }
}

class ClosureTypeBase
{
    public function value(): int { return 1; }
}

class ClosureTypeChild extends ClosureTypeBase
{
    public function value(): int { return 9; }
}

function closureTypedLocal(ClosureTypeValue $value): int
{
    $make = static fn(ClosureTypeValue $input): ClosureTypeValue => $input;
    $item = $make($value);
    $count = static fn(int $number): int => $number + 1;
    $answer = $count(2);
    return $item->value() + $answer;
}

function closureTypedEscape(ClosureTypeValue $value, int $amount): Closure
{
    return static function (ClosureTypeValue $input) use ($value, $amount): int {
        return $input->value() + $value->value() + $amount;
    };
}

function closurePolymorphicEscape(): Closure
{
    return static fn(ClosureTypeBase $value): int => $value->value();
}

function closureNullableEscape(): Closure
{
    return static fn(?ClosureTypeValue $value): ?ClosureTypeValue => $value;
}

function closureRefCapture(mixed &$value): Closure
{
    return static function () use (&$value): mixed { return $value; };
}

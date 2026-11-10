<?php

namespace ConstantInference;

const LIMIT = 36;
const MASK = 1023;
const RATIO = 0.5;
const LABEL = 'constant-label';
const ENABLED = true;
const ITEMS = [1, 2];

function compare(int $value): bool { return $value > LIMIT; }
function pack(int $value): int { return ($value & MASK) | 7; }
function label(): string { return LABEL; }

interface Flags
{
    public const MASK = 1023;
}

class Values implements Flags
{
    public const LIMIT = 4;
    public const RATIO = 0.5;
    public const ENABLED = true;
    public const LABEL = 'class-label';
    public const COMBINED = self::LIMIT | self::MASK;

    public function pack(int $value): int { return ($value & self::MASK) | self::LIMIT; }
    public function scale(float $value): float { return $value * self::RATIO; }
    public function enabled(): bool { return self::ENABLED; }
    public function label(): string { return self::LABEL; }
    public function combined(): int { return self::COMBINED; }
    public function lateBound(): mixed { return static::LIMIT; }
}

final class ChildValues extends Values
{
    public const LIMIT = 'changed';
}

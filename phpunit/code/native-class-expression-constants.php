<?php

namespace NativeConstantExpressions;

#[\Native]
class BaseFlags
{
    public const A = 1;
    public const B = 2;
    public const MASK = self::A | self::B;

    public function mask(): int
    {
        return self::MASK;
    }
}

#[\Native]
class ChildFlags extends BaseFlags
{
    public const A = 8;
    public const TOTAL = parent::MASK + 4;
    public const LABEL = 'tmp_var_' . self::TOTAL;
    public const ENABLED = self::TOTAL > 3;
    public const NOTHING = self::ENABLED ? null : 1;
    public const RATIO = self::TOTAL / 2;

    public function inheritedMask(): int
    {
        return self::MASK;
    }

    public function total(): int
    {
        return self::TOTAL;
    }

    public function label(): string
    {
        return self::LABEL;
    }

    public function enabled(): bool
    {
        return self::ENABLED;
    }

    public function nothing(): mixed
    {
        return self::NOTHING;
    }

    public function ratio(): float
    {
        return self::RATIO;
    }
}

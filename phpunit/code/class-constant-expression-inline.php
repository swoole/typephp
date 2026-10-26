<?php

class ClassConstantExpressionInline
{
    public const CAP_A = 1;
    public const CAP_B = 2;

    public const ALL_MASK = self::CAP_A | self::CAP_B;

    public function readLeaf(): int
    {
        return self::CAP_A;
    }

    public function readMask(): int
    {
        return self::ALL_MASK;
    }
}

interface ClassConstantExpressionInlineFlags
{
    public const FLAG_A = 1;
    public const FLAG_B = 2;

    public const COMBINED = self::FLAG_A | self::FLAG_B;
}

class ClassConstantExpressionInlineFlagUser implements ClassConstantExpressionInlineFlags
{
    public function readCombined(): int
    {
        return self::COMBINED;
    }

    public function readCombinedDefault(int $value = self::COMBINED): int
    {
        return $value;
    }
}

#[Native]
class ClassConstantExpressionInlineNativeMarker
{
    public const LABEL = 'tmp_var_label';

    public function label(): string
    {
        return self::LABEL;
    }
}

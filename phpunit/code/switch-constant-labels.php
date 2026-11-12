<?php

final class SwitchConstantLabels
{
    public const ZERO = 0;
    public const ONE = 1;
    public const TWO = 2;
    public const ALSO_TWO = 2;
    public const ARITHMETIC = 1 + 1;
    public const TEXT = '2';

    public function native(int $value): int
    {
        switch ($value) {
            case self::ZERO: return 10;
            case self::ONE: return 20;
            case self::TWO: return 30;
            default: return 40;
        }
    }

    public function duplicate(int $value): string
    {
        switch ($value) {
            case self::TWO: return 'first';
            case self::ALSO_TWO: return 'second';
            default: return 'default';
        }
    }

    public function boolean(bool $value): string
    {
        switch ($value) {
            case self::TWO: return 'true';
            case self::ZERO: return 'false';
            default: return 'default';
        }
    }

    public function text(int $value): string
    {
        switch ($value) {
            case self::TEXT: return 'text';
            default: return 'default';
        }
    }

    public function arithmetic(int $value): string
    {
        switch ($value) {
            case self::ARITHMETIC: return 'arithmetic';
            default: return 'default';
        }
    }
}

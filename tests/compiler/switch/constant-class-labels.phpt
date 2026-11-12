--TEST--
Integer class constant switch labels retain comparison and loop-control semantics
--FILE--
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

function main(): void {
    $probe = new SwitchConstantLabels();
    foreach ([0, 1, 2, 3] as $value) { echo $probe->native($value), "\n"; }
    echo $probe->duplicate(2), "\n";
    echo $probe->boolean(true), ':', $probe->boolean(false), "\n";
    echo $probe->text(2), ':', $probe->arithmetic(2), "\n";
    for ($i = 0; $i < 3; $i++) {
        switch ($i) {
            case SwitchConstantLabels::ZERO: continue 2;
            case SwitchConstantLabels::ONE: break;
            default: break;
        }
        echo $i;
    }
    echo "\n";
}

?>
--EXPECT--
10
20
30
40
first
true:false
text:arithmetic
12

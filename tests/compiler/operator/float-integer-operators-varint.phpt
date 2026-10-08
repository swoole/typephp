--TEST--
Float operands of modulo, bitwise and shift operators produce integers with varint_types
--FILE--
<?php
use varint_types;

function floatFloat(float $a, float $b): void
{
    $mod = $a % $b;
    $and = $a & $b;
    $or = $a | $b;
    $xor = $a ^ $b;
    $left = $a << $b;
    $right = $a >> $b;
    var_dump($mod, $and, $or, $xor, $left, $right);
}

function intFloat(int $a, float $b): void
{
    $mod = $a % $b;
    $and = $a & $b;
    $or = $a | $b;
    $xor = $a ^ $b;
    $left = $a << $b;
    $right = $a >> $b;
    var_dump($mod, $and, $or, $xor, $left, $right);
}

function floatInt(float $a, int $b): void
{
    $mod = $a % $b;
    $and = $a & $b;
    $or = $a | $b;
    $xor = $a ^ $b;
    $left = $a << $b;
    $right = $a >> $b;
    var_dump($mod, $and, $or, $xor, $left, $right);
}

function typedAnd(float $a, float $b): int { return $a & $b; }
function modAssign(int $a, float $b): mixed { $a %= $b; return $a; }
function andAssign(int $a, float $b): mixed { $a &= $b; return $a; }
function orAssign(int $a, float $b): mixed { $a |= $b; return $a; }
function xorAssign(int $a, float $b): mixed { $a ^= $b; return $a; }
function leftShiftAssign(int $a, float $b): mixed { $a <<= $b; return $a; }
function rightShiftAssign(int $a, float $b): mixed { $a >>= $b; return $a; }

function main(): void
{
    // PHP deprecates fractional float-to-int coercion. Check values and types here.
    error_reporting(E_ERROR | E_WARNING);

    // Assign to fresh locals to check inferred types without a typed return coercion.
    echo "float/float\n";
    floatFloat(6.9, 3.9);
    echo "int/float\n";
    intFloat(7, 2.5);
    echo "float/int\n";
    floatInt(7.5, 2);

    echo "literals\n";
    var_dump(7.5 % 2.5, 7.5 & 2.5, 7.5 | 2.5, 7.5 ^ 2.5, 7.5 << 2.5, 7.5 >> 2.5);
    echo "typed return\n";
    var_dump(typedAnd(6.0, 3.0));

    // Declared int parameters keep the native ABI even with varint_types.
    echo "int parameter compound\n";
    var_dump(modAssign(7, 2.5), andAssign(7, 2.5), orAssign(7, 2.5));
    var_dump(xorAssign(7, 2.5), leftShiftAssign(7, 2.5), rightShiftAssign(7, 2.5));

    echo "local compound\n";
    $mod = 7; $mod %= 2.5;
    $and = 7; $and &= 2.5;
    $or = 7; $or |= 2.5;
    $xor = 7; $xor ^= 2.5;
    $left = 7; $left <<= 2.5;
    $right = 7; $right >>= 2.5;
    var_dump($mod, $and, $or, $xor, $left, $right);
}
?>
--EXPECT--
float/float
int(0)
int(2)
int(7)
int(5)
int(48)
int(0)
int/float
int(1)
int(2)
int(7)
int(5)
int(28)
int(1)
float/int
int(1)
int(2)
int(7)
int(5)
int(28)
int(1)
literals
int(1)
int(2)
int(7)
int(5)
int(28)
int(1)
typed return
int(2)
int parameter compound
int(1)
int(2)
int(7)
int(5)
int(28)
int(1)
local compound
int(1)
int(2)
int(7)
int(5)
int(28)
int(1)

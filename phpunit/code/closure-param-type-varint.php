<?php
/**
 * Test fixture for closure parameter type narrowing in varint_types mode.
 *
 * In varint mode, inferred int locals are stored in php::Var (not php::Int)
 * to retain Zend integer widening semantics. Closure parameters with type
 * declarations like int $param expect php::Int, but call-site variables are
 * php::Var. The compiler must handle this type mismatch correctly.
 */

use varint_types;

// --- Type declarations work correctly in varint mode ---

function varintTypeDeclInt(int $vd1): int
{
    $fn = fn(int $p) => $p + 1;
    $i = 42;
    return $fn($i);
}

function varintTypeDeclFloat(float $vd2): float
{
    $fn = fn(float $p) => $p * 2.0;
    $f = 3.14;
    return $fn($f);
}

// --- Inferred types in call sites ---

function varintInferredInt(): int
{
    $fn = fn(int $vi1) => $vi1 + 1;
    $a = 10;
    $b = 5;
    return $fn($a + $b);
}

function varintInferredIntSub(): int
{
    $fn = fn(int $vis1) => $vis1 - 1;
    $a = 20;
    $b = 3;
    return $fn($a - $b);
}

function varintInferredIntMul(): int
{
    $fn = fn(int $vim1) => $vim1 * 2;
    $a = 7;
    $b = 4;
    return $fn($a * $b);
}

function varintInferredIntMod(): int
{
    $fn = fn(int $vimod1) => $vimod1 + 1;
    $a = 10;
    $b = 3;
    return $fn($a % $b);
}

function varintInferredIntShiftLeft(): int
{
    $fn = fn(int $visl1) => $visl1 + 1;
    $a = 3;
    $b = 2;
    return $fn($a << $b);
}

function varintInferredIntShiftRight(): int
{
    $fn = fn(int $visr1) => $visr1 + 1;
    $a = 16;
    $b = 2;
    return $fn($a >> $b);
}

// --- Mixed type scenarios ---

function varintMixedParams(): array
{
    $fn = fn(int $vmx1, float $vmx2) => [$vmx1, $vmx2];
    $i = 42;
    $f = 3.14;
    return $fn($i, $f);
}

function varintBinaryPow(): int
{
    $fn = fn(int $vbp1) => $vbp1 + 1;
    $a = 2;
    $b = 3;
    return $fn($a ** $b);
}

// --- Float division in varint mode (non-constant → Variant) ---

function varintFloatDiv(): void
{
    $fn = fn($vfdiv1) => $vfdiv1;
    $a = 3.14;
    $b = 2;
    var_dump($fn($a / $b));
}

// --- Main function for testing ---

function main(): void
{
    echo "varintTypeDeclInt: " . varintTypeDeclInt(0) . "\n";
    echo "varintTypeDeclFloat: " . varintTypeDeclFloat(0.0) . "\n";
    echo "varintInferredInt: " . varintInferredInt() . "\n";
    echo "varintInferredIntSub: " . varintInferredIntSub() . "\n";
    echo "varintInferredIntMul: " . varintInferredIntMul() . "\n";
    echo "varintInferredIntMod: " . varintInferredIntMod() . "\n";
    echo "varintInferredIntShiftLeft: " . varintInferredIntShiftLeft() . "\n";
    echo "varintInferredIntShiftRight: " . varintInferredIntShiftRight() . "\n";
    $mixed = varintMixedParams();
    echo "varintMixedParams: [" . $mixed[0] . ", " . $mixed[1] . "]\n";
    echo "varintBinaryPow: " . varintBinaryPow() . "\n";
    varintFloatDiv();
}

--TEST--
bigint_types defaults preserve numeric values in native scalar parameters
--FILE--
<?php
use bigint_types;

function integer(int $value = 42): int { return $value; }
function floating(float $value = 42): float { return $value; }
function negative(int $value = -42): int { return $value; }
function compound(int $value = 6 * 7): int { return $value; }
function bitwise(int $value = ~42): int { return $value; }
function zero(bool $value = 0): bool { return $value; }
function nonzero(bool $value = 42): bool { return $value; }
function cancelled(bool $value = 42 - 42): bool { return $value; }
function outOfRange(int $value = 9223372036854775808): int { return $value; }

function main(): void
{
    var_dump(integer(), floating(), negative(), compound(), bitwise());
    var_dump(zero(), nonzero(), cancelled());
    try {
        var_dump(outOfRange());
    } catch (ArithmeticError $e) {
        echo "range error\n";
    }
}
?>
--EXPECT--
int(42)
float(42)
int(-42)
int(42)
int(-43)
bool(false)
bool(true)
bool(false)
range error

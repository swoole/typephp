--TEST--
decimal_types defaults convert zero, negative and fractional numeric values
--FILE--
<?php
use decimal_types;

function floating(float $value = 2.5): float { return $value; }
function integer(int $value = -7.9): int { return $value; }
function zero(bool $value = 0.0): bool { return $value; }
function nonzero(bool $value = -0.5): bool { return $value; }
function compound(float $value = 1.25 + 1.25): float { return $value; }

function main(): void
{
    var_dump(floating(), integer(), zero(), nonzero(), compound());
}
?>
--EXPECT--
float(2.5)
int(-7)
bool(false)
bool(true)
float(2.5)

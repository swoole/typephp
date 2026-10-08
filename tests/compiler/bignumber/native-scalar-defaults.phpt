--TEST--
Big-number defaults convert their payload into native scalar parameters
--FILE--
<?php
function positive(float $value = 0.123456789012345678): float { return $value; }
function negative(float $value = -0.123456789012345678): float { return $value; }
function unaryPlus(float $value = +0.123456789012345678): float { return $value; }
function compound(float $value = 0.123456789012345678 * 2): float { return $value; }
function integer(int $value = 7.123456789012345678): int { return $value; }
function nonzero(bool $value = 0.123456789012345678): bool { return $value; }
function zero(bool $value = 0.123456789012345678 - 0.123456789012345678): bool { return $value; }
function selection(float $value = true ? 0.123456789012345678 : 0.987654321098765432): float { return $value; }

class Defaults
{
    const VALUE = -0.123456789012345678;
    public static function get(float $value = self::VALUE): float { return $value; }
}

function main(): void
{
    var_dump(positive(), negative(), unaryPlus(), compound());
    var_dump(integer(), nonzero(), zero(), selection(), Defaults::get());
    // Explicit arguments still use the conversion introduced by PR #138.
    var_dump(positive(std::decimal('2.5')), integer(std::bigInt('42')));
}
?>
--EXPECT--
float(0.12345678901234568)
float(-0.12345678901234568)
float(0.12345678901234568)
float(0.24691357802469135)
int(7)
bool(true)
bool(false)
float(0.12345678901234568)
float(-0.12345678901234568)
float(2.5)
int(42)

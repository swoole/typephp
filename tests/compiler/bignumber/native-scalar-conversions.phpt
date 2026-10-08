--TEST--
BigInt/BigFloat/Decimal values convert by value (not by box resource handle) into native int/float/bool slots
--FILE--
<?php
function takesFloat(float $x): float { return $x; }
function takesInt(int $x): int { return $x; }
function takesBool(bool $x): bool { return $x; }

// A float literal with 16+ fractional digits is recognized as a Decimal literal.
function returnsFloatFromDecimalLiteral(): float { return 0.123456789012345678; }
function returnsIntFromBigInt(): int { return std::bigInt('42'); }

function main(): void
{
    // Arguments
    var_dump(takesFloat(0.123456789012345678));
    var_dump(takesFloat(std::decimal('1.5')));
    var_dump(takesFloat(std::bigInt('42')));
    var_dump(takesFloat(std::bigFloat('2.25')));
    var_dump(takesInt(std::bigInt('42')));
    var_dump(takesInt(std::decimal('7.9')));
    var_dump(takesBool(std::bigInt('0')));
    var_dump(takesBool(std::decimal('0.5')));

    // Returns
    var_dump(returnsFloatFromDecimalLiteral());
    var_dump(returnsIntFromBigInt());

    // A fixed native local receiving a Decimal literal
    $f = 0.0;
    $f = 0.123456789012345678;
    var_dump($f);
}
?>
--EXPECT--
float(0.12345678901234568)
float(1.5)
float(42)
float(2.25)
int(42)
int(7)
bool(false)
bool(true)
float(0.12345678901234568)
int(42)
float(0.12345678901234568)

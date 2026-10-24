--TEST--
Match expression with high-precision BigInt and Decimal values preserves boxed Variant representation
--FILE--
<?php
function testBigInt(int $flag)
{
    $value = match ($flag) {
        1 => std::bigInt('123'),
        default => std::bigInt('456'),
    };
    return $value;
}

function testDecimal(int $flag)
{
    $value = match ($flag) {
        1 => std::decimal('12.5'),
        default => std::decimal('45.5'),
    };
    return $value;
}

function main(): void
{
    var_dump(std::bigInt(testBigInt(1))->toString());
    var_dump(std::bigInt(testBigInt(2))->toString());
    var_dump(std::decimal(testDecimal(1))->toString());
    var_dump(std::decimal(testDecimal(2))->toString());
}
?>
--EXPECT--
string(3) "123"
string(3) "456"
string(4) "12.5"
string(4) "45.5"

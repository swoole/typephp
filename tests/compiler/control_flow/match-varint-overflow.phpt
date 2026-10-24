--TEST--
Match expression with arithmetic in varint_types mode keeps dynamic Variant result and checks return type on overflow
--FILE--
<?php
use varint_types;

function exampleMixed(int $x, int $y): mixed
{
    return match ($x) {
        1 => $y + 1,
        default => 0,
    };
}

function exampleInt(int $x, int $y): int
{
    return match ($x) {
        1 => $y + 1,
        default => 0,
    };
}

function main(): void
{
    var_dump(exampleMixed(1, 10));
    $res = exampleMixed(1, PHP_INT_MAX);
    var_dump(is_float($res));
    var_dump(exampleInt(1, 10));
    try {
        exampleInt(1, PHP_INT_MAX);
        echo "FAIL: expected TypeError\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECTF--
int(11)
bool(true)
int(11)
exampleInt(): Return value must be of type int, float returned

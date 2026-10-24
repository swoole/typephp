--TEST--
Match expressions preserve overflow through nested value expressions in varint_types mode
--FILE--
<?php
use varint_types;

function nestedMixed(int $x, int $y, bool $flag): mixed
{
    return match ($x) {
        1 => ($flag ? $y + 1 : 0),
        2 => ($flag ? 0 : $y + 1),
        3 => @($y + 1),
        4 => match ($flag) { true => ($flag ? $y + 1 : 0), default => 0 },
        default => 0,
    };
}

function nestedInt(int $y, bool $flag): int
{
    return match ($flag) {
        true => ($flag ? $y + 1 : 0),
        default => 0,
    };
}

function main(): void
{
    var_dump(nestedMixed(1, 10, true));
    var_dump(nestedMixed(1, PHP_INT_MAX, false));
    var_dump(nestedMixed(2, PHP_INT_MAX, true));
    var_dump(nestedMixed(0, PHP_INT_MAX, true));
    var_dump(is_float(nestedMixed(1, PHP_INT_MAX, true)));
    var_dump(is_float(nestedMixed(2, PHP_INT_MAX, false)));
    var_dump(is_float(nestedMixed(3, PHP_INT_MAX, true)));
    var_dump(is_float(nestedMixed(4, PHP_INT_MAX, true)));
    var_dump(nestedInt(10, true));
    try {
        nestedInt(PHP_INT_MAX, true);
        echo "FAIL: expected TypeError\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
int(11)
int(0)
int(0)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
int(11)
nestedInt(): Return value must be of type int, float returned

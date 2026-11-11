--TEST--
Coalesce retains varint overflow and heterogeneous branch values
--FILE--
<?php
use varint_types;
function selectionOverflow(int $value): mixed { return ($value + 1) ?? 0; }
function selectionHeterogeneous(?int $value): mixed { return $value ?? 'missing'; }
function main(): void
{
    var_dump(is_float(selectionOverflow(PHP_INT_MAX)));
    var_dump(selectionOverflow(2));
    var_dump(selectionHeterogeneous(null), selectionHeterogeneous(0));
}
?>
--EXPECT--
bool(true)
int(3)
string(7) "missing"
int(0)

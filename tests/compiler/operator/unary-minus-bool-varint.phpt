--TEST--
varint_types preserves integer results when negating booleans
--FILE--
<?php
declare(strict_types=1);
use varint_types;
function neg(bool $a): mixed { return -$a; }
function negLocal(bool $a): mixed { $result = -$a; return $result; }
function negReference(bool &$a): mixed { return -$a; }
function negMixed(mixed $a): mixed { return -$a; }
function main(): void {
    var_dump(-true, -false, -(-true), -(-false));
    var_dump(neg(true), neg(false), negLocal(true), negLocal(false));
    $flag = true;
    $negative = -$flag;
    var_dump($negative, is_int($negative), $negative + 2, $negative === -1);
    var_dump(negReference($flag), $flag);
    $flag = false;
    var_dump(negReference($flag), $flag);
    var_dump(negMixed(true), negMixed(false));
}
?>
--EXPECT--
int(-1)
int(0)
int(1)
int(0)
int(-1)
int(0)
int(-1)
int(0)
int(-1)
bool(true)
int(1)
bool(true)
int(-1)
bool(true)
int(0)
bool(false)
int(-1)
int(0)

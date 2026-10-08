--TEST--
Unary minus promotes booleans to integers for literals, locals, references and returns
--FILE--
<?php
declare(strict_types=1);
class BoolHolder {
    public bool $value = true;
    public ?bool $optional = null;
    public static bool $flag = false;
}
function neg(bool $a): mixed { return -$a; }
function negInt(bool $a): int { return -$a; }
function negLocal(bool $a): mixed { $result = -$a; return $result; }
function negReference(bool &$a): mixed { return -$a; }
function negMixed(mixed $a): mixed { return -$a; }
function boolOnce(int &$calls): bool { ++$calls; return true; }
function main(): void {
    var_dump(-true, -false, -(-true), -(-false));
    var_dump(neg(true), neg(false), negInt(true), negInt(false));
    var_dump(negLocal(true), negLocal(false));
    $flag = true;
    $negative = -$flag;
    var_dump($negative, is_int($negative), $negative + 2, $negative === -1);
    var_dump(negReference($flag), $flag);
    $flag = false;
    var_dump(negReference($flag), $flag);
    var_dump(negMixed(true), negMixed(false));
    $holder = new BoolHolder();
    var_dump(-$holder->value, -BoolHolder::$flag, -$holder->optional);
    $holder->optional = true;
    var_dump(-$holder->optional);
    $calls = 0;
    $once = -boolOnce($calls);
    var_dump($once, $calls);
    var_dump(-($flag = true), $flag);
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
int(-1)
int(0)
int(0)
int(-1)
int(-1)
int(1)
int(-1)
bool(true)

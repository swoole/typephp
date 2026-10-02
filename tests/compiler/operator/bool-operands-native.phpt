--TEST--
A bool operand compares as a bool, negates to an int and is an int in arithmetic (native mode)
--FILE--
<?php
declare(strict_types=1);
function lt(bool $a, int $b): bool { return $a < $b; }
function le(bool $a, int $b): bool { return $a <= $b; }
function gt(int $a, bool $b): bool { return $a > $b; }
function ge(bool $a, float $b): bool { return $a >= $b; }
function lts(bool $a, string $b): bool { return $a < $b; }
function gts(string $a, bool $b): bool { return $a > $b; }
function add(bool $a, int $b): mixed { return $a + $b; }
function mul(bool $a, bool $b): mixed { return $a * $b; }
function sub(float $a, bool $b): mixed { return $a - $b; }
function divb(int $a, bool $b): mixed { return $a / $b; }
function modb(int $a, bool $b): mixed { return $a % $b; }
function negb(bool $a): mixed { return -$a; }
function negs(string $s): mixed { return -$s; }
function negLocal(bool $a): mixed { $x = -$a; return $x; }

function t(string $nome, callable $f): void
{
    try {
        $v = var_export($f(), true);
        echo $nome, ' => ', $v, "\n";
    } catch (\Throwable $e) {
        echo $nome, ' => ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

function main(): void
{
    ini_set('display_errors', '0');
    t('false < -7', fn() => lt(false, -7));
    t('true < 5', fn() => lt(true, 5));
    t('false <= -7', fn() => le(false, -7));
    t('true <= 0', fn() => le(true, 0));
    t('-7 > false', fn() => gt(-7, false));
    t('0 > true', fn() => gt(0, true));
    t('true >= 5.5', fn() => ge(true, 5.5));
    t('false >= 0.0', fn() => ge(false, 0.0));
    t('false < "abc"', fn() => lts(false, 'abc'));
    t('true < "0"', fn() => lts(true, '0'));
    t('"" > false', fn() => gts('', false));
    t('"x" > false', fn() => gts('x', false));
    t('true + 41', fn() => add(true, 41));
    t('true * true', fn() => mul(true, true));
    t('2.5 - true', fn() => sub(2.5, true));
    t('7 / true', fn() => divb(7, true));
    t('7 % true', fn() => modb(7, true));
    t('-true', fn() => negb(true));
    t('-false', fn() => negb(false));
    t('-"5"', fn() => negs('5'));
    t('-"1.5"', fn() => negs('1.5'));
    t('-"abc"', fn() => negs('abc'));
    t('$x = -true', fn() => negLocal(true));
    t('7 % false', fn() => modb(7, false));
}
?>
--EXPECT--
false < -7 => true
true < 5 => false
false <= -7 => true
true <= 0 => false
-7 > false => true
0 > true => false
true >= 5.5 => true
false >= 0.0 => true
false < "abc" => true
true < "0" => false
"" > false => false
"x" > false => true
true + 41 => 42
true * true => 1
2.5 - true => 1.5
7 / true => 7
7 % true => 0
-true => -1
-false => 0
-"5" => -5
-"1.5" => -1.5
-"abc" => TypeError: Unsupported operand types: string * int
$x = -true => -1
7 % false => DivisionByZeroError: Modulo by zero

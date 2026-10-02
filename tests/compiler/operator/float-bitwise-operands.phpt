--TEST--
%, <<, >>, &, | and ^ convert float operands to int (as PHP does) and produce an int
--FILE--
<?php
function band(float $a, float $b): mixed { return $a & $b; }
function bor(float $a, int $b): mixed { return $a | $b; }
function bxor(int $a, float $b): mixed { return $a ^ $b; }
function shl(float $a, float $b): mixed { return $a << $b; }
function shr(float $a, int $b): mixed { return $a >> $b; }
function md(float $a, int $b): mixed { $x = $a % $b; return $x; }
function andAssign(float $a): mixed { $x = 7; $x &= $a; return $x; }
function modAssign(float $a): mixed { $x = 7; $x %= $a; return $x; }

function t(callable $f): void
{
    try {
        var_dump($f());
    } catch (\Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

function main(): void
{
    ini_set('display_errors', '0'); // lossy float-to-int conversions are deprecated
    t(fn() => band(6.0, 3.0));
    t(fn() => band(-1.0, 255.0));
    t(fn() => band(1.5, 1.0));
    t(fn() => band(NAN, 1.0));
    t(fn() => bor(2.0, PHP_INT_MAX));
    t(fn() => bxor(PHP_INT_MAX, 1.0));
    t(fn() => shl(1.0, 3.0));
    t(fn() => shl(1.0, 64.0));
    t(fn() => shl(1.0, -1.0));
    t(fn() => shr(-8.0, 1));
    t(fn() => md(7.5, 2));
    t(fn() => md(7.5, 0));
    t(fn() => andAssign(3.0));
    t(fn() => modAssign(2.5));
    t(fn() => 6.0 & 3.0);
}
?>
--EXPECT--
int(2)
int(255)
int(1)
int(0)
int(9223372036854775807)
int(9223372036854775806)
int(8)
int(0)
ArithmeticError: Bit shift by negative number
int(-4)
int(1)
DivisionByZeroError: Modulo by zero
int(3)
int(1)
int(2)

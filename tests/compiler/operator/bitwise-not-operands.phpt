--TEST--
~ inverts the bytes of a string and raises TypeError for bool, as in PHP
--FILE--
<?php
declare(strict_types=1);

function notb(bool $a): mixed { return ~$a; }
function nots(string $s): mixed { return ~$s; }
function notsLocal(string $s): string { $x = ~$s; return $x; }
function noti(int $i): mixed { return ~$i; }
function notf(float $f): mixed { return ~$f; }
function notm(mixed $m): mixed { return ~$m; }
function twice(string $s): mixed { return ~~$s; }

function t(string $nome, callable $f): void
{
    try {
        $v = $f();
        echo $nome, ' => ', is_string($v) ? 'string:' . bin2hex($v) : var_export($v, true), "\n";
    } catch (\Throwable $e) {
        echo $nome, ' => ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

function main(): void
{
    ini_set('display_errors', '0');
    t('~false', fn() => notb(false));
    t('~true', fn() => notb(true));
    t('~"abc"', fn() => nots('abc'));
    t('~""', fn() => nots(''));
    t('~"1e3"', fn() => nots('1e3'));
    t('$x = ~"ab"', fn() => notsLocal('ab'));
    t('~~"xyz"', fn() => twice('xyz'));
    t('~5', fn() => noti(5));
    t('~PHP_INT_MIN', fn() => noti(PHP_INT_MIN));
    t('~1.5', fn() => notf(1.5));
    t('~mixed int', fn() => notm(7));
}
?>
--EXPECT--
~false => TypeError: Cannot perform bitwise not on false
~true => TypeError: Cannot perform bitwise not on true
~"abc" => string:9e9d9c
~"" => string:
~"1e3" => string:ce9acc
$x = ~"ab" => string:9e9d
~~"xyz" => string:78797a
~5 => -6
~PHP_INT_MIN => 9223372036854775807
~1.5 => -2
~mixed int => -8

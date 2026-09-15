--TEST--
Closure parameter type errors are reported in PHP's argument order
--FILE--
<?php
declare(strict_types=1);

function arg_no(Throwable $e): string
{
    return preg_match('/Argument #(\d+)/', $e->getMessage(), $m) ? $m[1] : '?';
}

function main(): void
{
    // Both parameters native: the cast for $first must run before $second.
    $native = fn(int $first, int $second) => 0;
    try {
        $native('bad-first', 'bad-second');
    } catch (TypeError $e) {
        echo 'native:', arg_no($e), "\n";
    }

    // Composite first, native second: PHP checks #1 before converting #2.
    $compositeFirst = fn(?int $first, int $second) => 0;
    try {
        $compositeFirst('bad-first', 'bad-second');
    } catch (TypeError $e) {
        echo 'composite-first:', arg_no($e), "\n";
    }

    // Native first, composite second.
    $compositeSecond = fn(int $first, ?int $second) => 0;
    try {
        $compositeSecond('bad-first', 'bad-second');
    } catch (TypeError $e) {
        echo 'composite-second:', arg_no($e), "\n";
    }

    // Three parameters.
    $three = fn(int $p1, int $p2, int $p3) => 0;
    try {
        $three('x', 'y', 'z');
    } catch (TypeError $e) {
        echo 'three:', arg_no($e), "\n";
    }

    // Only the second argument is invalid: still reported as #2.
    $secondOnly = fn(int $first, int $second) => 0;
    try {
        $secondOnly(1, 'bad-second');
    } catch (TypeError $e) {
        echo 'second-only:', arg_no($e), "\n";
    }

    // Union declaration in first position.
    $union = fn(int|string $first, int $second) => 0;
    try {
        $union([], 'bad-second');
    } catch (TypeError $e) {
        echo 'union-first:', arg_no($e), "\n";
    }
}
?>
--EXPECT--
native:1
composite-first:1
composite-second:1
three:1
second-only:2
union-first:1

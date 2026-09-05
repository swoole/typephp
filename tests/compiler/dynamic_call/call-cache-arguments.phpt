--TEST--
Dynamic call cache preserves fixed, named, unpacked, reference, and exception arguments
--FILE--
<?php

function cached_sum(int $a, int $b, int $c, int $d, int $e = 0): int
{
    return $a + $b + $c + $d + $e;
}

function cached_increment(int &$value): int
{
    return ++$value;
}

function cached_throw(string $message): never
{
    throw new RuntimeException($message);
}

class CachedArgumentHolder
{
    public int $value = 6;
}

function main(): void
{
    $sum = 'cached_sum';
    var_dump($sum(1, 2, 3, 4));
    var_dump($sum(1, 2, 3, 4, 5));
    var_dump($sum(d: 4, c: 3, b: 2, a: 1));

    $holder = new CachedArgumentHolder();
    $values = [7];
    var_dump($sum($holder->value, $values[0], 3, 4, 5));

    $arguments = [1, 2, 3, 4, 5];
    var_dump($sum(...$arguments));

    $increment = 'cached_increment';
    $value = 10;
    var_dump($increment(refval($value)));
    var_dump($value);

    $throw = 'cached_throw';
    try {
        $throw('cached failure');
    } catch (RuntimeException $exception) {
        echo $exception->getMessage(), "\n";
    }
}
?>
--EXPECT--
int(10)
int(15)
int(10)
int(25)
int(15)
int(11)
int(11)
cached failure

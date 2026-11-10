--TEST--
Nested native and dynamic calls isolate typed-ref bridges while alias chains reuse each call's root
--FILE--
<?php
function nested_alias_increment(mixed &$first, mixed &$second): int
{
    $first += 10;
    ++$second;
    return (int) $first;
}

function nested_alias_observe(int $value, mixed &$first, mixed &$second): void
{
    var_dump($value, $first, $second);
    $first += $value;
    ++$second;
}

function main(): void
{
    $root = std::int(1);
    $first =& $root;
    $second =& $first;
    nested_alias_observe(nested_alias_increment($first, $second), $first, $second);
    var_dump($root, $first, $second);

    $root = 1;
    $inner = 'nested_alias_increment';
    $outer = 'nested_alias_observe';
    $outer(
        $inner(std::ref($first), std::ref($second)),
        std::ref($first),
        std::ref($second),
    );
    var_dump($root, $first, $second);
}
?>
--EXPECT--
int(12)
int(12)
int(12)
int(25)
int(25)
int(25)
int(12)
int(12)
int(12)
int(25)
int(25)
int(25)

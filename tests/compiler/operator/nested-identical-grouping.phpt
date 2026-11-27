--TEST--
Native strict comparisons preserve nested expression grouping and operand effects
--FILE--
<?php

function nested_identity(bool $ready, int $index, int $limit): void
{
    var_dump($ready === ($index === $limit));
    var_dump(($index === $limit) === $ready);
    var_dump($ready !== ($index === $limit));
    var_dump(($index === $limit) !== $ready);
    var_dump(true === ($ready === ($index === $limit)));
    var_dump(false !== ($ready === ($index === $limit)));
    var_dump(($ready === true) === ($index === $limit));
    var_dump($ready === ($index < $limit));
    var_dump($ready === ($index <= $limit && $index > 0));
    var_dump($ready === ($ready ? false : true));
}

function identity_trace(bool $value): bool
{
    echo $value ? 'T' : 'F';
    return $value;
}

function main(): void
{
    nested_identity(false, 1, 120);
    nested_identity(true, 120, 120);
    nested_identity(true, 1, 120);
    nested_identity(false, 120, 120);
    $number = 1.5;
    $other = 0.0;
    var_dump($number === ($other = 1.5));
    var_dump($other);
    $integer = 5;
    $assigned = 0;
    var_dump($integer === ($assigned = 5));
    var_dump($assigned);
    var_dump(true === (identity_trace(false) === false));
    var_dump((identity_trace(false) === identity_trace(false)) === identity_trace(true));
}
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
bool(true)
bool(true)
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(false)
bool(false)
bool(false)
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
bool(true)
bool(false)
bool(false)
bool(true)
float(1.5)
bool(true)
int(5)
Fbool(true)
FFTbool(true)

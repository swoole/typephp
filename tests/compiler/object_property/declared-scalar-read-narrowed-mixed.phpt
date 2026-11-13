--TEST--
instanceof narrowed mixed receivers keep the Variant property read
--FILE--
<?php
final class NarrowedScalarBox
{
    public int $count = 3;
}

function readCount(mixed $value): int
{
    if ($value instanceof NarrowedScalarBox) {
        return $value->count;
    }
    return -1;
}

function main(): void
{
    var_dump(readCount(new NarrowedScalarBox()));
    var_dump(readCount(new stdClass()));
}
?>
--EXPECT--
int(3)
int(-1)

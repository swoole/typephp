--TEST--
nullable declared scalar properties keep returning null instead of a typed read
--FILE--
<?php
final class NullableScalarBox
{
    public ?int $count = null;
}

function readCount(NullableScalarBox $box): ?int
{
    return $box->count;
}

function main(): void
{
    $box = new NullableScalarBox();
    var_dump(readCount($box));
    $box->count = 42;
    var_dump(readCount($box));
    $box->count = null;
    var_dump(readCount($box));
}
?>
--EXPECT--
NULL
int(42)
NULL

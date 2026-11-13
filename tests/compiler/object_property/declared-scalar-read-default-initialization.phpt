--TEST--
declared scalar properties without defaults read as zero through typed receivers
--FILE--
<?php
final class DefaultScalarBox
{
    public int $count;
    public float $ratio;
}

function readCount(DefaultScalarBox $box): int
{
    return $box->count;
}

function main(): void
{
    $box = new DefaultScalarBox();
    var_dump(readCount($box));
    var_dump($box->count);
    var_dump($box->ratio);
}
?>
--EXPECT--
int(0)
int(0)
float(0)

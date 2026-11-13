--TEST--
non-final receivers keep the name-based read when a subclass redeclares the property
--FILE--
<?php
class BaseScalarBox
{
    public int $count = 1;

    public function own(): int
    {
        return $this->count;
    }
}

class ChildScalarBox extends BaseScalarBox
{
    public int $count = 2;
}

function readCount(BaseScalarBox $box): int
{
    return $box->count;
}

function main(): void
{
    var_dump((new BaseScalarBox())->own());
    var_dump(readCount(new ChildScalarBox()));
    $box = new ChildScalarBox();
    $box->count = 5;
    var_dump(readCount($box));
}
?>
--EXPECT--
int(1)
int(2)
int(5)

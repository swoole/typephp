--TEST--
final class public int and float properties read through typed receivers
--FILE--
<?php
final class ScalarBox
{
    public int $count = 0;
    public float $ratio = 0.0;

    public function total(): float
    {
        return $this->ratio + $this->count;
    }
}

function accumulate(ScalarBox $box, int $iterations): float
{
    $sum = 0.0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $box->ratio + $box->count;
    }
    return $sum;
}

function main(): void
{
    $box = new ScalarBox();
    $box->count = 7;
    $box->ratio = 1.5;
    var_dump(accumulate($box, 10));
    var_dump($box->total());
}
?>
--EXPECT--
float(85)
float(8.5)

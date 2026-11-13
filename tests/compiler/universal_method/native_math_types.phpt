--TEST--
Native int/float math methods keep the receiver type; jsonDecodeToObject requires an object
--FILE--
<?php
function run(int $a, int $i, float $f): void
{
    var_dump($a->max($i), $a->min($i));
    var_dump($f->max(1.0), $f->min(3.5), $f->abs(), $f->pow(2.0));
    $n = 0 - $a;
    var_dump($n->abs());
    var_dump('{"a":1}'->jsonDecodeToObject()->a);
    try {
        '5'->jsonDecodeToObject();
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}

function main(): void
{
    run(7, 3, 2.5);
}
?>
--EXPECT--
int(7)
int(3)
float(2.5)
float(2.5)
float(2.5)
float(6.25)
int(7)
int(1)
Return value must be of type object, int returned

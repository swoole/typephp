--TEST--
Dynamic array writes and compound writes preserve references, property storage and copy-on-write
--FILE--
<?php

class DynamicArrayWriteHolder
{
    public array $values = [];
    public mixed $dynamic;
    public static mixed $shared;
}

function arrayWriteContainer(mixed $value): mixed
{
    return $value;
}

function main(): void
{
    $value = arrayWriteContainer(10);
    $holder = new DynamicArrayWriteHolder();
    $holder->values = [&$value];
    $holder->values[0] = 20;
    $holder->values[0] += 3;
    var_dump($value, $holder->values[0]);

    $holder->dynamic = [&$value];
    $holder->dynamic[0] = 30;
    $holder->dynamic[0] /= 2;
    var_dump($value, $holder->dynamic[0]);

    DynamicArrayWriteHolder::$shared = [&$value];
    DynamicArrayWriteHolder::$shared[0] = 40;
    DynamicArrayWriteHolder::$shared[0] *= 2;
    var_dump($value, DynamicArrayWriteHolder::$shared[0]);

    $dynamic = arrayWriteContainer([&$value]);
    $dynamic[0] = 50;
    var_dump($dynamic[0] += 5);
    var_dump($value);

    $holder->values = [1];
    $copy = $holder->values;
    $holder->values[0] += 2;
    $holder->values[] = 4;
    var_dump($holder->values, $copy);

    $holder->dynamic = [5];
    $dynamicCopy = $holder->dynamic;
    $holder->dynamic[0] *= 2;
    $holder->dynamic[] = 6;
    var_dump($holder->dynamic, $dynamicCopy);
}
?>
--EXPECT--
int(23)
int(23)
int(15)
int(15)
int(80)
int(80)
int(55)
int(55)
array(2) {
  [0]=>
  int(3)
  [1]=>
  int(4)
}
array(1) {
  [0]=>
  int(1)
}
array(2) {
  [0]=>
  int(10)
  [1]=>
  int(6)
}
array(1) {
  [0]=>
  int(5)
}

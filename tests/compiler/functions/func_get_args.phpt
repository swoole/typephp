--TEST--
func_get_args
--FILE--
<?php

function foo1($a, $b, $c = 10)
{
    var_dump(func_get_args());
}

function foo2(...$args) {
    var_dump(func_get_args());
}

function foo3($a, $b, $c, ...$args) {
    var_dump(func_get_args());
}

function main()
{
   foo1(2.5, 3);
   foo2(1, 3, 5, 8, 10);
   foo3(1, 3, 5, 8, 10, 12);
}
?>
--EXPECT--
array(3) {
  [0]=>
  float(2.5)
  [1]=>
  int(3)
  [2]=>
  int(10)
}
array(5) {
  [0]=>
  int(1)
  [1]=>
  int(3)
  [2]=>
  int(5)
  [3]=>
  int(8)
  [4]=>
  int(10)
}
array(6) {
  [0]=>
  int(1)
  [1]=>
  int(3)
  [2]=>
  int(5)
  [3]=>
  int(8)
  [4]=>
  int(10)
  [5]=>
  int(12)
}

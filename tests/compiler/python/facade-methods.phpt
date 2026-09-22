--TEST--
Python facade methods retain their PHP return types and inherited behavior
--SKIPIF--
<?php
if (!extension_loaded('phpy')) {
    die('skip phpy extension is not loaded');
}
?>
--FILE--
<?php

function main(): void
{
    $list = python\list([1, 2, 3]);
    $count = $list->count();
    $contains = $list->contains(2);
    $slice = $list->slice(0, 2)->toArray();
    $set = python\set([1, 2]);
    $member = $set->contains(1);
    $value = python\int(42);
    $value = $list;
    var_dump($count, $contains, $slice, $member, $value->toArray());
}
?>
--EXPECT--
int(3)
bool(true)
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
bool(true)
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}

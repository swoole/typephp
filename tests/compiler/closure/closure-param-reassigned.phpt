--TEST--
A narrowed Closure parameter stays writable with PHP's dynamic semantics
--FILE--
<?php
declare(strict_types=1);

function main(): void
{
    // PHP allows re-assigning a parameter to any other type, so a parameter
    // narrowed from its call sites must not become a fixed-type local: the
    // value would be truncated (float 1.5 stored into an int) or rejected.
    $toStr = function ($x) {
        $x = "str";
        return $x;
    };
    var_dump($toStr(42));

    $toFloat = function ($x) {
        $x = 1.5;
        return $x;
    };
    var_dump($toFloat(42));

    $inc = function ($x) {
        $x++;
        return $x;
    };
    var_dump($inc(42));

    $append = function ($x) {
        $x[] = 9;
        return $x;
    };
    var_dump($append([1, 2]));
}
?>
--EXPECT--
string(3) "str"
float(1.5)
int(43)
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(9)
}

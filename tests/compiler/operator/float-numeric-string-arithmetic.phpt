--TEST--
Float-valued numeric string arithmetic keeps PHP float semantics
--FILE--
<?php
function main(): void
{
    var_dump("1e2" / 4);
    var_dump("1e2" + 0);
    var_dump("2e1" * 2);
    var_dump("2e1" ** 2);
    var_dump("-2e1" + 0);
    var_dump(".5" + 0);
    var_dump(" 1.5 " + 0);
    $x = "1e2" / 4;
    var_dump($x);
    $y = "1.5" * 2;
    var_dump($y);
    $f = "1e2";
    var_dump($f / 4);
    var_dump("5" + 1);
    var_dump("10" / 2);
    var_dump("1e400" + 0.0);
    var_dump(is_float("9223372036854775808" + 0));
    var_dump("9223372036854775807" + 0);
    $x = "9223372036854775808" * 2;
    var_dump(is_float($x));
    var_dump($x > PHP_INT_MAX);
    var_dump("2.5" ** "2");
    var_dump("9223372036854775807" ** 2);
    var_dump("1e300" * "1e300");
    var_dump("1.5" < 2);
}
?>
--EXPECT--
float(25)
float(100)
float(40)
float(400)
float(-20)
float(0.5)
float(1.5)
float(25)
float(3)
float(25)
int(6)
int(5)
float(INF)
bool(true)
int(9223372036854775807)
bool(true)
bool(true)
float(6.25)
float(8.507059173023462E+37)
float(INF)
bool(true)

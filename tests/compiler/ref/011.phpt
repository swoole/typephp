--TEST--
Reference arguments use explicit calls instead of eval caller-context capture
--FILE--
<?php
function foo2(int &$v) : int {
    $v += 30;
    return $v;
}
function main()
{
    $i = 100;
    $r = foo2($i);
    var_dump($r);
}
?>
--EXPECT--
int(130)

--TEST--
Unary plus uses the converted assignment result for typed properties
--FILE--
<?php
class UnaryAssignmentBox {
    public float $number = 0.0;
    public ?float $optional = null;
    public static float $shared = 0.0;
    public static ?float $nullableShared = null;
}
function assignedOnce(int &$calls): int { ++$calls; return 2; }
function main() {
    $box = new UnaryAssignmentBox();
    var_dump(+($box->number = 2), $box->number);
    var_dump(+(UnaryAssignmentBox::$shared = 3), UnaryAssignmentBox::$shared);
    var_dump(+($box->optional = 4), +($box->optional = null));
    var_dump(+(UnaryAssignmentBox::$nullableShared = 5), +(UnaryAssignmentBox::$nullableShared = null));
    var_dump(+@($box->number = 6));
    $calls = 0;
    var_dump(+($box->number = assignedOnce($calls)), $calls);
    var_dump(+($local = 7), $local);
}
?>
--EXPECT--
float(2)
float(2)
float(3)
float(3)
float(4)
int(0)
float(5)
int(0)
float(6)
float(2)
int(1)
int(7)
int(7)

--TEST--
varint_types does not change PHP boolean comparison rules for parameters or inferred locals
--FILE--
<?php
use varint_types;
function compareBoolInt(bool $a, int $b): array {
    return [$a == $b, $a != $b, $a < $b, $a <= $b, $a > $b, $a >= $b, $a <=> $b, $a === $b, $a !== $b];
}
function compareFloatBool(float $a, bool $b): array {
    return [$a == $b, $a != $b, $a < $b, $a <= $b, $a > $b, $a >= $b, $a <=> $b, $a === $b, $a !== $b];
}
function main(): void {
    $a = false;
    $b = -7;
    var_dump($a <= $b, $b > $a, $a == $b, $b != $a, $b <=> $a, $a === $b);
    var_dump(false <= -7, true >= 5.5, -7 > false, true == 2);
    echo json_encode(compareBoolInt(false, -7)), "\n";
    echo json_encode(compareBoolInt(true, 2)), "\n";
    echo json_encode(compareBoolInt(false, 0)), "\n";
    echo json_encode(compareFloatBool(-5.5, false)), "\n";
    echo json_encode(compareFloatBool(-0.0, true)), "\n";
    echo json_encode(compareFloatBool(NAN, true)), "\n";
}
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(true)
int(1)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
[false,true,true,true,false,false,-1,false,true]
[true,false,false,true,false,true,0,false,true]
[true,false,false,true,false,true,0,false,true]
[false,true,false,false,true,true,1,false,true]
[false,true,true,true,false,false,-1,false,true]
[true,false,false,true,false,true,0,false,true]

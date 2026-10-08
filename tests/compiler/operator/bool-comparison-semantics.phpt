--TEST--
Boolean comparisons follow PHP truthiness in both operand orders and retain strict types
--FILE--
<?php
function compareBoolInt(bool $a, int $b): array {
    return [$a == $b, $a != $b, $a <> $b, $a < $b, $a <= $b, $a > $b, $a >= $b, $a <=> $b, $a === $b, $a !== $b];
}
function compareIntBool(int $a, bool $b): array {
    return [$a == $b, $a != $b, $a <> $b, $a < $b, $a <= $b, $a > $b, $a >= $b, $a <=> $b, $a === $b, $a !== $b];
}
function compareBoolFloat(bool $a, float $b): array {
    return [$a == $b, $a != $b, $a <> $b, $a < $b, $a <= $b, $a > $b, $a >= $b, $a <=> $b, $a === $b, $a !== $b];
}
function compareFloatBool(float $a, bool $b): array {
    return [$a == $b, $a != $b, $a <> $b, $a < $b, $a <= $b, $a > $b, $a >= $b, $a <=> $b, $a === $b, $a !== $b];
}
function compareBools(bool $a, bool $b): array {
    return [$a == $b, $a != $b, $a <> $b, $a < $b, $a <= $b, $a > $b, $a >= $b, $a <=> $b, $a === $b, $a !== $b];
}
function main(): void {
    var_dump(false <= -7, true >= 5.5, -7 > false, true == 2, false != -1);
    $a = false;
    $b = -7;
    var_dump($a <= $b, $b > $a, $a === $b);
    echo json_encode(compareBoolInt(false, -7)), "\n";
    echo json_encode(compareIntBool(-7, false)), "\n";
    echo json_encode(compareBoolInt(true, 2)), "\n";
    echo json_encode(compareIntBool(2, true)), "\n";
    echo json_encode(compareBoolInt(false, 0)), "\n";
    echo json_encode(compareIntBool(0, true)), "\n";
    echo json_encode(compareBoolInt(true, PHP_INT_MIN)), "\n";
    echo json_encode(compareIntBool(PHP_INT_MAX, false)), "\n";
    echo json_encode(compareBoolFloat(true, 5.5)), "\n";
    echo json_encode(compareFloatBool(-5.5, false)), "\n";
    echo json_encode(compareBoolFloat(false, -0.0)), "\n";
    echo json_encode(compareFloatBool(-0.0, true)), "\n";
    echo json_encode(compareBoolFloat(true, NAN)), "\n";
    echo json_encode(compareFloatBool(NAN, false)), "\n";
    echo json_encode(compareBoolFloat(false, INF)), "\n";
    echo json_encode(compareFloatBool(-INF, true)), "\n";
    echo json_encode(compareBools(false, true)), "\n";
    echo json_encode(compareBools(true, true)), "\n";
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
[false,true,true,true,true,false,false,-1,false,true]
[false,true,true,false,false,true,true,1,false,true]
[true,false,false,false,true,false,true,0,false,true]
[true,false,false,false,true,false,true,0,false,true]
[true,false,false,false,true,false,true,0,false,true]
[false,true,true,true,true,false,false,-1,false,true]
[true,false,false,false,true,false,true,0,false,true]
[false,true,true,false,false,true,true,1,false,true]
[true,false,false,false,true,false,true,0,false,true]
[false,true,true,false,false,true,true,1,false,true]
[true,false,false,false,true,false,true,0,false,true]
[false,true,true,true,true,false,false,-1,false,true]
[true,false,false,false,true,false,true,0,false,true]
[false,true,true,false,false,true,true,1,false,true]
[false,true,true,true,true,false,false,-1,false,true]
[true,false,false,false,true,false,true,0,false,true]
[false,true,true,true,true,false,false,-1,false,true]
[true,false,false,false,true,false,true,0,true,false]

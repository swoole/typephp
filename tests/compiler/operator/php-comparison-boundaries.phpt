--TEST--
PHP comparison paths preserve strings, arrays, null, NaN and operand evaluation order
--FILE--
<?php
function compareBoolString(bool $a, string $b): array {
    return [$a == $b, $a != $b, $a < $b, $a <= $b, $a > $b, $a >= $b, $a <=> $b];
}
function compareBoolArray(bool $a, array $b): array {
    return [$a == $b, $a != $b, $a < $b, $a <= $b, $a > $b, $a >= $b, $a <=> $b];
}
function compareArrayBool(array $a, bool $b): array {
    return [$a == $b, $a != $b, $a < $b, $a <= $b, $a > $b, $a >= $b, $a <=> $b];
}
function compareMixed(mixed $a, mixed $b): array {
    return [$a == $b, $a != $b, $a < $b, $a <= $b, $a > $b, $a >= $b, $a <=> $b];
}
function compareNumbers(int $a, float $b): array {
    return [$a == $b, $a != $b, $a < $b, $a <= $b, $a > $b, $a >= $b, $a <=> $b];
}
function leftBool(int &$calls): bool { ++$calls; echo "left\n"; return false; }
function rightInt(int &$calls): int { ++$calls; echo "right\n"; return -7; }
function main(): void {
    echo json_encode(compareBoolString(false, "0")), "\n";
    echo json_encode(compareBoolString(true, "abc")), "\n";
    echo json_encode(compareBoolArray(false, [])), "\n";
    echo json_encode(compareBoolArray(false, [1])), "\n";
    echo json_encode(compareArrayBool([1], true)), "\n";
    echo json_encode(compareMixed(false, null)), "\n";
    echo json_encode(compareMixed(null, -7)), "\n";
    echo json_encode(compareMixed(["a" => 1], ["b" => 1])), "\n";
    echo json_encode(compareMixed(NAN, 0.0)), "\n";
    echo json_encode(compareMixed(0.0, NAN)), "\n";
    echo json_encode(compareNumbers(0, NAN)), "\n";
    echo json_encode(compareNumbers(PHP_INT_MAX, (float) PHP_INT_MAX)), "\n";
    $calls = 0;
    var_dump(leftBool($calls) <= rightInt($calls), $calls);
    var_dump(leftBool($calls) == rightInt($calls), $calls);
}
?>
--EXPECT--
[true,false,false,true,false,true,0]
[true,false,false,true,false,true,0]
[true,false,false,true,false,true,0]
[false,true,true,true,false,false,-1]
[true,false,false,true,false,true,0]
[true,false,false,true,false,true,0]
[false,true,true,true,false,false,-1]
[false,true,false,false,false,false,1]
[false,true,false,false,false,false,1]
[false,true,false,false,false,false,1]
[false,true,false,false,false,false,1]
[true,false,false,true,false,true,0]
left
right
bool(true)
int(2)
left
right
bool(false)
int(4)

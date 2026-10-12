--TEST--
by-ref variadic cleanup survives short-circuit capture regions
--FILE--
<?php
function gFalse(&$v): bool {
    $v = $v + 1;
    return false;
}
function bump(&$first, &...$rest): void {
    $first += 10;
    foreach ($rest as &$value) {
        $value += 10;
    }
}
function main(): void {
    $a = 1;
    $skipped = gFalse($a) && bump($a, $a);
    var_dump($a, $skipped);
    $b = 1;
    null ?? bump($b, $b);
    var_dump($b);
}
?>
--EXPECT--
int(2)
bool(false)
int(21)

--TEST--
foreach list assignments retain existing native scalar targets and evaluate keys once
--FILE--
<?php

class ForeachNativeBox
{
    public int $value = 0;
}

function foreachNativeKey(): int
{
    $GLOBALS['foreachNativeKeys']++;
    return 0;
}

function main(): void
{
    for ($dx = -2; $dx <= 2; ++$dx) {
    }
    for ($dy = -2; $dy <= 2; ++$dy) {
    }
    $float = 0.0;
    $bool = false;
    $text = '';
    foreach ([[1, 2, 1.5, true, 'east'], [-1, -2, 2.5, false, 'west']] as [$dx, $dy, $float, $bool, $text]) {
        var_dump($dx, $dy, $float, $bool, $text);
    }
    foreach ([['point' => [7, 8]], ['point' => [9, 10]]] as ['point' => [$dx, $dy]]) {
        var_dump($dx, $dy);
    }
    $box = new ForeachNativeBox();
    foreach ([[11], [13]] as [$box->value]) {
        var_dump($box->value);
    }
    $GLOBALS['foreachNativeKeys'] = 0;
    foreach ([[17], [19]] as [foreachNativeKey() => $dx]) {
        var_dump($dx);
    }
    var_dump($GLOBALS['foreachNativeKeys']);
}
?>
--EXPECT--
int(1)
int(2)
float(1.5)
bool(true)
string(4) "east"
int(-1)
int(-2)
float(2.5)
bool(false)
string(4) "west"
int(7)
int(8)
int(9)
int(10)
int(11)
int(13)
int(17)
int(19)
int(2)

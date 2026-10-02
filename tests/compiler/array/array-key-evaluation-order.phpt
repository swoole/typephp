--TEST--
An array literal evaluates a side-effecting key before its value, also when the value nests a call
--FILE--
<?php
function f(string $s): string { echo "[$s]"; return $s; }

function main(): void
{
    $a = [f("k1") => strtoupper(f("v1")), f("k2") => f("v2")];
    echo "\n", json_encode($a), "\n";
    $b = [f("x") => str_repeat(f("y"), 2), ...[f("z")]];
    echo "\n", json_encode($b), "\n";
}
?>
--EXPECT--
[k1][v1][k2][v2]
{"k1":"V1","k2":"v2"}
[x][y][z]
{"x":"yy","0":"z"}

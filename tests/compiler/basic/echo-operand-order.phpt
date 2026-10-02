--TEST--
echo prints each operand before evaluating the next, also when an operand nests a call
--FILE--
<?php
function f(string $s): string { echo "[f:$s]"; return $s; }
function boom(): string { throw new RuntimeException('boom'); }

function main(): void
{
    echo "A", var_export(f("1"), true), "\n";
    echo "B", strtoupper(f("2")), "\n";
    echo "C", str_repeat(f("3"), 2), "\n";
    $g = fn() => f("4");
    echo "D", var_export($g(), true), "\n";
    try {
        echo "E-", var_export(boom(), true), "\n";
    } catch (RuntimeException $e) {
        echo "| caught ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
A[f:1]'1'
B[f:2]2
C[f:3]33
D[f:4]'4'
E-| caught boom

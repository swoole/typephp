--TEST--
Integer switch subjects accept floating-point case labels
--FILE--
<?php
function select_number(int $value): string
{
    switch ($value) {
        case 0:
            return 'zero';
        case 1.5:
            return 'fraction';
        case 2.0:
            return 'two';
        default:
            return 'default';
    }
}

function main(): void
{
    echo select_number(0), "\n";
    echo select_number(1), "\n";
    echo select_number(2), "\n";
}
?>
--EXPECT--
zero
default
two

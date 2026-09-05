--TEST--
Boolean switch subjects use PHP loose comparison for numeric cases
--FILE--
<?php
function select_bool(bool $value): string
{
    switch ($value) {
        case 2:
            return 'nonzero';
        case 0:
            return 'zero';
        default:
            return 'default';
    }
}

function select_binary_bool(bool $value): string
{
    switch ($value) {
        case 0:
            return 'false';
        case 1:
            return 'true';
    }
    return 'default';
}

function main(): void
{
    echo select_bool(true), "\n";
    echo select_bool(false), "\n";
    echo select_binary_bool(true), "\n";
    echo select_binary_bool(false), "\n";
}
?>
--EXPECT--
nonzero
zero
true
false

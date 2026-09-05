--TEST--
Switch evaluates and matches a trailing empty case
--FILE--
<?php
function mark(string $value): string
{
    echo "evaluated:$value\n";
    return $value;
}

function main(): void
{
    switch ('subject') {
        default:
            echo "default\n";
            break;
        case mark('other'):
    }

    switch ('subject') {
        default:
            echo "default\n";
            break;
        case mark('subject'):
    }
}
?>
--EXPECT--
evaluated:other
default
evaluated:subject

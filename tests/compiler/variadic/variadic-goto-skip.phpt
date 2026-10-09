--TEST--
A forward goto may skip a by-value variadic call without crossing an initialization
--FILE--
<?php
function count_values(...$values): int {
    return count($values);
}

function main(): void {
    if (time() > 0) {
        goto finished;
    }
    count_values(1, 2);
    finished:
    echo "done\n";
}
?>
--EXPECT--
done

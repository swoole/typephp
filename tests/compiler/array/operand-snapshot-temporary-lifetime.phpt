--TEST--
Array operand snapshots keep objects alive through RHS mutation and release them at statement end
--FILE--
<?php
final class OperandSnapshotLifetime
{
    public function __destruct()
    {
        echo "destroyed\n";
    }
}

function snapshot_operand_source(array $source): array
{
    return $source;
}

function replace_snapshot_source(array &$source): array
{
    $source = [];
    echo "rhs\n";
    return [];
}

function main(): void
{
    $source = [new OperandSnapshotLifetime()];
    $same = snapshot_operand_source($source) === replace_snapshot_source($source);
    echo "after\n";
    var_dump($same, $source);
}
?>
--EXPECT--
rhs
destroyed
after
bool(false)
array(0) {
}

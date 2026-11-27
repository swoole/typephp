--TEST--
Array assignment operands own their values before compound writes or reentrant callbacks
--FILE--
<?php

class OperandSnapshotBag implements ArrayAccess
{
    public mixed $value = 10;
    public array $calls = [];

    public function offsetExists(mixed $offset): bool { return true; }
    public function offsetGet(mixed $offset): mixed
    {
        $this->calls[] = ['get', $offset];
        $GLOBALS['snapshot_rhs']['value'] = 99;
        return $this->value;
    }
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->calls[] = ['set', $offset, $value];
        $this->value = $value;
    }
    public function offsetUnset(mixed $offset): void {}
}

function changeSnapshotKey(array &$keys): int
{
    $keys['slot'] = 'after';
    return 7;
}

function operandSnapshots(mixed $key): void
{
    $rows = [];
    $totals = ['group' => ['value' => 10]];
    foreach ([3, 4] as $value) {
        $row = ['value' => $value];
        $rows[] = $row;
        $totals['group'][$key] += $row[$key];
    }
    echo json_encode([$rows, $totals]), PHP_EOL;

    $source = ['value' => 3];
    $sink = ['nested' => ['value' => 40]];
    $answers = [];
    $answers[] = ($sink['nested'][$key] += $source[$key]);
    $answers[] = ($sink['nested'][$key] -= $source[$key]);
    $answers[] = ($sink['nested'][$key] *= $source[$key]);
    $answers[] = ($sink['nested'][$key] /= $source[$key]);
    $answers[] = ($sink['nested'][$key] %= $source[$key]);
    $answers[] = ($sink['nested'][$key] **= $source[$key]);
    $answers[] = ($sink['nested'][$key] <<= $source[$key]);
    $answers[] = ($sink['nested'][$key] >>= $source[$key]);
    $answers[] = ($sink['nested'][$key] &= $source[$key]);
    $answers[] = ($sink['nested'][$key] |= $source[$key]);
    $answers[] = ($sink['nested'][$key] ^= $source[$key]);
    $answers[] = ($sink['nested'][$key] .= $source[$key]);
    echo json_encode([$answers, $source, $sink], JSON_PRESERVE_ZERO_FRACTION), PHP_EOL;

    $ref =& $source[$key];
    $sink['nested'][$key] = 10;
    $sink['nested'][$key] += $ref;
    echo json_encode([$source, $sink]), PHP_EOL;

    $GLOBALS['snapshot_rhs'] = ['value' => 3];
    $bag = new OperandSnapshotBag();
    $result = ($bag[$key] += $GLOBALS['snapshot_rhs'][$key]);
    echo json_encode([$result, $bag->value, $bag->calls, $GLOBALS['snapshot_rhs']]), PHP_EOL;
}

function snapshotComputedKey(mixed $key): void
{
    $keys = ['slot' => 'before'];
    $target = ['nested' => []];
    $target['nested'][$keys[$key]] = changeSnapshotKey($keys);
    echo json_encode([$target, $keys]), PHP_EOL;
    $keys = ['slot' => 'before'];
    $target = [];
    $target[$keys[$key]]['value'] = changeSnapshotKey($keys);
    echo json_encode([$target, $keys]), PHP_EOL;
}

function main(): void
{
    operandSnapshots('value');
    snapshotComputedKey('slot');
}
?>
--EXPECT--
[[{"value":3},{"value":4}],{"group":{"value":17}}]
[[43,40,120,40,1,1,8,1,1,3,0,"03"],{"value":3},{"nested":{"value":"03"}}]
[{"value":3},{"nested":{"value":13}}]
[13,13,[["get","value"],["set","value",13]],{"value":99}]
[{"nested":{"before":7}},{"slot":"after"}]
[{"before":{"value":7}},{"slot":"after"}]

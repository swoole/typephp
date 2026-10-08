--TEST--
Array keys preserve PHP variable reads, property snapshots, increments and reference writeback
--FILE--
<?php
class ArrayKeyReadOrder {
    public string $key = 'old';
    public int $n = 0;
    public function value(): string { $this->key = 'new'; return 'value'; }
}
function array_key_change(string &$key): string { $key = 'changed'; return $key; }
function main(): void
{
    $key = 'old';
    $a = [$key => ($key = 'new')];
    echo json_encode($a), "\n";
    $o = new ArrayKeyReadOrder();
    $a = [$o->key => $o->value()];
    echo json_encode($a), "\n";
    $a = [$o->n++ => $o->n, $o->n++ => $o->n];
    echo json_encode($a), "\n";
    $keys = ['key' => 'before'];
    $a = [$keys['key'] => ($keys['key'] = 'after')];
    echo json_encode($a), "\n";
    $key = 'old';
    $a = [array_key_change($key) => $key];
    echo json_encode($a), "\n";
    $key = 'old';
    $a = [$key => array_key_change($key)];
    echo json_encode($a), "\n";
    $ref = std::any('old');
    $a = [array_key_change($ref) => &$ref];
    $ref = 'updated';
    echo json_encode($a), "\n";
}
?>
--EXPECT--
{"new":"new"}
{"old":"value"}
[1,2]
{"before":"after"}
{"changed":"changed"}
{"changed":"changed"}
{"changed":"updated"}

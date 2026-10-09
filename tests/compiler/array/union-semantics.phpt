--TEST--
PHP array union preserves keys, order, left values, references and copy-on-write
--FILE--
<?php
const UNION_VALUES = [0 => 'left'];

class UnionState {
    public const array VALUES = [0 => 'right', 1 => 'new'];
    public const int NUMBER = 2;
    public array $values = ['key' => 'left'];
    public static array $shared = [5 => 'left'];
}

function union_arrays(array $left, array $right): array {
    return $left + $right;
}

function union_into(array &$left, array $right): void {
    $left += $right;
}

function dynamic_union($left, $right) {
    return $left + $right;
}

function main(): void {
    $left = [5 => 'left', 'key' => null, -2 => 'negative'];
    $right = [5 => 'right', 'key' => 'right', 9 => 'new', 'extra' => 'added'];
    $expected = [5 => 'left', 'key' => null, -2 => 'negative', 9 => 'new', 'extra' => 'added'];
    var_dump($left + $right);
    var_dump($right + $left === [5 => 'right', 'key' => 'right', 9 => 'new', 'extra' => 'added', -2 => 'negative']);
    var_dump(union_arrays($left, $right) === $expected);
    var_dump(dynamic_union($left, $right) === $expected);
    var_dump([1, 2] + [3, 4, 5] === [1, 2, 5]);
    var_dump([] + $left === $left, $left + [] === $left, $left + $left === $left);
    var_dump(['0' => 'left'] + [0 => 'right', 2 => 'new'] === [0 => 'left', 2 => 'new']);
    var_dump(['nested' => ['left' => 1]] + ['nested' => ['right' => 2]] === ['nested' => ['left' => 1]]);
    var_dump(([1] + [2, 3]) + [4, 5, 6] === [1, 3, 6]);
    var_dump(UNION_VALUES + UnionState::VALUES === [0 => 'left', 1 => 'new']);
    var_dump(+UnionState::NUMBER);

    $copy = $left;
    $left += $right;
    var_dump($left === $expected, $copy === [5 => 'left', 'key' => null, -2 => 'negative']);
    $left += $left;
    var_dump($left === $expected);
    union_into($copy, $right);
    var_dump($copy === $expected);

    $state = new UnionState();
    $state->values += ['key' => 'right', 'new' => 1];
    UnionState::$shared += [5 => 'right', 9 => 'new'];
    var_dump($state->values === ['key' => 'left', 'new' => 1]);
    var_dump(UnionState::$shared === [5 => 'left', 9 => 'new']);

    $list = std::list([1, 2]);
    $other = std::list([3, 4, 5]);
    var_dump($list + $other === [1, 2, 5]);

    $value = std::any(1);
    $referenced = ['value' => &$value];
    $union = $referenced + ['value' => 9, 'new' => 2];
    $value = 3;
    var_dump($union['value'], $union['new']);
    $union['new'] = 4;
    var_dump($referenced === ['value' => 3]);
}
?>
--EXPECT--
array(5) {
  [5]=>
  string(4) "left"
  ["key"]=>
  NULL
  [-2]=>
  string(8) "negative"
  [9]=>
  string(3) "new"
  ["extra"]=>
  string(5) "added"
}
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(2)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(3)
int(2)
bool(true)

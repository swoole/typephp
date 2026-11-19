--TEST--
Nested array writes resolve slots after callbacks mutate their container, preserving PHP evaluation order
--FILE--
<?php

class ReentrantArrayHolder
{
    public array $rows = [[1]];
    public static array $shared = [[1]];
    public static array $other = [];

    public function erase(): int
    {
        unset($this->rows[0]);
        return 7;
    }

    public function replace(): int
    {
        $this->rows = [[40]];
        return 2;
    }

    public function grow(): int
    {
        for ($i = 1; $i <= 50; ++$i) {
            $this->rows[$i] = [$i];
        }
        return 9;
    }

    public function fail(): int
    {
        throw new RuntimeException('rhs');
    }
}

class ReentrantOffsetBag implements ArrayAccess
{
    public mixed $value = 0;
    public function offsetExists(mixed $offset): bool { return true; }
    public function offsetGet(mixed $offset): mixed { echo 'G;'; return $this; }
    public function offsetSet(mixed $offset, mixed $value): void { echo 'S;'; $this->value = $value; }
    public function offsetUnset(mixed $offset): void {}
}

class ReentrantMagicHolder
{
    public ReentrantOffsetBag $actual;
    public function __construct() { $this->actual = new ReentrantOffsetBag(); }
    public function __get(string $name): mixed { echo 'P;'; return $this->actual; }
}

class ReentrantOuterHolder
{
    public ReentrantArrayHolder $inner;
    public function __construct() { $this->inner = new ReentrantArrayHolder(); }
    public function replace(): int { $this->inner = new ReentrantArrayHolder(); return 88; }
    public function write(): void { $this->inner->rows[0][0] = $this->replace(); }
}

function reentrantOuterKey(): int { echo 'K1;'; return 0; }
function reentrantInnerKey(): int { echo 'K2;'; return 0; }
function reentrantValue(): int { echo 'V;'; return 3; }
function reentrantMember(): string { echo 'N;'; return 'virtual'; }
function reentrantGlobalName(): string { echo 'F;'; return 'write_lifetime_global'; }
function reentrantReceiver(ReentrantArrayHolder $holder): ReentrantArrayHolder { echo 'R;'; return $holder; }
function reentrantReplaceHolder(mixed &$holder): int { $holder = new ReentrantArrayHolder(); return 99; }
function reentrantDynamicHolder(): mixed { return new ReentrantArrayHolder(); }
function reentrantDynamicValue(): mixed { return 12; }
function reentrantClassName(): string { echo 'C;'; return ReentrantArrayHolder::class; }
function reentrantStaticMember(): string { echo 'SN;'; return 'shared'; }

function main(): void
{
    $holder = new ReentrantArrayHolder();
    $holder->rows[0][0] = $holder->erase();
    echo json_encode($holder->rows), ':', count($holder->rows), "\n";
    $holder->rows[0][0] += $holder->replace();
    echo json_encode($holder->rows), "\n";
    $holder->rows[][] = $holder->erase();
    echo json_encode($holder->rows), "\n";

    $holder->rows = [[1]];
    $holder->rows[0][0] = $holder->grow();
    var_dump(count($holder->rows), $holder->rows[0][0], $holder->rows[50][0]);
    $holder->rows = [];
    try { $holder->rows[17][23] = $holder->fail(); }
    catch (RuntimeException $error) { echo $error->getMessage(), ':', count($holder->rows), "\n"; }

    reentrantReceiver($holder)->rows[reentrantOuterKey()][reentrantInnerKey()] = reentrantValue();
    echo json_encode($holder->rows), "\n";
    $bag = new ReentrantOffsetBag();
    $bag[reentrantOuterKey()][reentrantInnerKey()] = reentrantValue();
    echo $bag->value, "\n";
    $magic = new ReentrantMagicHolder();
    $magic->{reentrantMember()}[reentrantOuterKey()][reentrantInnerKey()] = reentrantValue();
    echo $magic->actual->value, "\n";
    $GLOBALS[reentrantGlobalName()][reentrantOuterKey()][reentrantInnerKey()] = reentrantValue();
    echo json_encode($GLOBALS['write_lifetime_global']), "\n";

    $property = 'shared';
    ReentrantArrayHolder::${$property}[0][0] = ($property = 'other') === 'other' ? 8 : 9;
    echo json_encode(ReentrantArrayHolder::$shared), ':', json_encode(ReentrantArrayHolder::$other), "\n";
    reentrantClassName()::${reentrantStaticMember()}[0][0] = reentrantValue();
    echo json_encode(ReentrantArrayHolder::$shared), "\n";
    $className = ReentrantArrayHolder::class;
    $className::$shared[0][0] = (($className = 'MissingClassForReentrantWrite') !== '' ? 4 : 5);
    echo json_encode(ReentrantArrayHolder::$shared), "\n";
    try { $className::$shared[0][0] = reentrantValue(); }
    catch (Error $error) { echo "missing\n"; }
    $key = 'old';
    $holder->rows = [];
    $holder->rows[$key][0] = ($key = 'new');
    echo json_encode($holder->rows), "\n";
    $dynamic = reentrantDynamicHolder();
    $oldDynamic = $dynamic;
    $dynamic->rows[0][0] = reentrantReplaceHolder($dynamic);
    echo json_encode($oldDynamic->rows), ':', json_encode($dynamic->rows), "\n";
    $outer = new ReentrantOuterHolder();
    $oldInner = $outer->inner;
    $outer->write();
    echo json_encode($oldInner->rows), ':', json_encode($outer->inner->rows), "\n";
    $copy = [['a' => 1]];
    $copy[0]['copy'] = $copy;
    echo json_encode($copy), "\n";
    $value = reentrantDynamicValue();
    $holder->rows = [[&$value]];
    $holder->rows[0][0] = 13;
    var_dump($value);
}
?>
--EXPECT--
[[7]]:1
[[42]]
{"1":[7]}
int(51)
int(9)
int(50)
rhs:0
R;K1;K2;V;[[3]]
K1;K2;V;G;S;3
N;K1;K2;V;P;G;S;3
F;K1;K2;V;[[3]]
[[1]]:[[8]]
C;SN;V;[[3]]
[[4]]
missing
{"new":["new"]}
[[1]]:[[99]]
[[1]]:[[88]]
[{"a":1,"copy":[{"a":1}]}]
int(13)

--TEST--
by-ref variadic writes back to local variable arguments
--FILE--
<?php
class Box {
    public int $p = 5;
    public function bump(int &...$vals): void {
        foreach ($vals as &$v) { $v += 10; }
    }
}
function bump(int &...$vals): void {
    foreach ($vals as &$v) { $v += 10; }
}
function bumpu(&...$vals): void {
    foreach ($vals as &$v) { $v = $v + 10; }
}
function main(): void
{
    $a = 1;
    bump($a);
    var_dump($a);
    for ($i = 0; $i < 3; $i++) {
        bump($a);
    }
    var_dump($a);
    $c = 2;
    bump(vals: $c);
    var_dump($c);
    bumpu($a);
    var_dump($a);
    $b = new Box();
    $b->bump($b->p);
    var_dump($b->p);
    $arr = [7];
    bump($arr[0]);
    var_dump($arr[0]);
}
?>
--EXPECT--
int(11)
int(41)
int(12)
int(51)
int(15)
int(17)

--TEST--
SSA object prop: this object argument escape prevents property hoisting
--FILE--
<?php
class Foo {
    public int $a;

    public function run(): void {
        $this->a = 1;

        make_ref($this);
        $this->a += 1;

        var_dump($this->a);
    }
}

function make_ref(Foo $object): void {
    $ref =& $object->a;
    $ref = 99;
}

function main(): void {
    (new Foo())->run();
}
?>
--EXPECT--
int(100)

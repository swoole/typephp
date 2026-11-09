--TEST--
Single Trait with simple trait method
--FILE--
<?php
trait THello {
    public const array CONST_ARRAY = [ 'foo' ];
}

class TraitsTest {
    use THello;
}

class Test2 extends TraitsTest {
}

class Test3 extends Test2 {
    public function foo() {
        var_dump(static::CONST_ARRAY);
    }
}

function main() {
    $o2 = new Test3();
    var_dump(Test3::CONST_ARRAY);
    var_dump(Test3::CONST_ARRAY);
    $o2->foo();
}
?>
--EXPECT--
array(1) {
  [0]=>
  string(3) "foo"
}
array(1) {
  [0]=>
  string(3) "foo"
}
array(1) {
  [0]=>
  string(3) "foo"
}

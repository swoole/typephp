--TEST--
get_parent_class() function
--FILE--
<?php

class Base {}
class Child extends Base {}
class NoParent {}

function main() {
    $child = new Child();
    $noParent = new NoParent();

    echo "get_parent_class:\n";
    echo get_parent_class($child) === "Base" ? "ok-obj\n" : "fail-obj\n";
    echo get_parent_class(Child::class) === "Base" ? "ok-cls\n" : "fail-cls\n";
    echo get_parent_class($noParent) === false ? "ok-obj-false\n" : "fail-obj-false\n";
    echo get_parent_class(NoParent::class) === false ? "ok-cls-false\n" : "fail-cls-false\n";
    echo get_parent_class('RuntimeException') === "Exception" ? "ok-internal-cls\n" : "fail-internal-cls\n";
    echo get_parent_class('Exception') === false ? "ok-internal-cls-false\n" : "fail-internal-cls-false\n";

    echo "done\n";
}
?>
--EXPECT--
get_parent_class:
ok-obj
ok-cls
ok-obj-false
ok-cls-false
ok-internal-cls
ok-internal-cls-false
done

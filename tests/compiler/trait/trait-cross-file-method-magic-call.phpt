--TEST--
Trait methods on classes declaring __call are called directly and not downgraded to __call
--FILE--
<?php

trait TraitA
{
    public function getAttribute(string $cls): string
    {
        return 'attr:' . $cls;
    }
}

class ClassB
{
    use TraitA;

    public function __call(string $name, array $args): mixed
    {
        return 'magic:' . $name;
    }
}

function main(): void
{
    $obj = new ClassB();
    var_dump($obj->getAttribute('SomeClass'));
    var_dump($obj->nonExistent('test'));
}
?>
--EXPECT--
string(14) "attr:SomeClass"
string(17) "magic:nonExistent"

--TEST--
Trait method with `self` return type flattened into a class that implements an interface declaring `self` return
--FILE--
<?php

interface TestInterface
{
    public function test(): self;
}

trait TestTrait
{
    public function test(): self
    {
        return $this;
    }
}

class TestClass implements TestInterface
{
    use TestTrait;
}

function main()
{
    $test = new TestClass;
    // The trait method's `self` resolves to the consuming class (TestClass),
    // which must be compatible with the interface's `self` (TestInterface).
    $result = $test->test();
    var_dump($result instanceof TestClass);
    var_dump($result === $test);
    var_dump($result instanceof TestInterface);
    var_dump((string) (new ReflectionMethod(TestClass::class, 'test'))->getReturnType());
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
string(9) "TestClass"

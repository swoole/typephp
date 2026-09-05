--TEST--
Dynamic static method name on object target uses the runtime method value
--FILE--
<?php

class DynamicObjectStaticTarget
{
    public static function reward(string $value): string
    {
        return 'base:' . $value;
    }
}

class DynamicObjectStaticChild extends DynamicObjectStaticTarget
{
    public static function reward(string $value): string
    {
        return 'child:' . $value;
    }
}

function main(): void
{
    $method = 'reward';
    $target = new DynamicObjectStaticTarget();
    $child = new DynamicObjectStaticChild();

    var_dump($target::$method('first'));
    var_dump($child::$method(value: 'second'));
}
?>
--EXPECT--
string(10) "base:first"
string(12) "child:second"

--TEST--
Typed property receiver dispatches a child public method over a parent private method
--FILE--
<?php

class TypedPropertyPrivateBase
{
    private function value(): string
    {
        return 'base';
    }
}

final class TypedPropertyPrivateChild extends TypedPropertyPrivateBase
{
    public function value(): string
    {
        return 'child';
    }
}

final class TypedPropertyPrivateHolder
{
    public TypedPropertyPrivateBase $target;

    public function __construct()
    {
        $this->target = new TypedPropertyPrivateChild();
    }

    public function run(): string
    {
        return $this->target->value();
    }
}

function main(): void
{
    echo (new TypedPropertyPrivateHolder())->run(), "\n";
}

?>
--EXPECT--
child

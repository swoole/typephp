--TEST--
Dynamic method names follow PHP method-call semantics, not callable parsing
--FILE--
<?php

class DynamicNameBase
{
    public function run(): string
    {
        return 'base';
    }
}

class DynamicNameChild extends DynamicNameBase
{
    public function run(): string
    {
        return 'child';
    }

    public function invoke(mixed $method): string
    {
        return $this->$method();
    }
}

class DynamicNameMagic
{
    public function __call(string $name, array $arguments): string
    {
        return 'magic:' . $name;
    }
}

class DynamicNameStatic
{
    public static function leak(): string
    {
        return 'leaked';
    }
}

function invoke_method(object $object, mixed $method): string
{
    return $object->$method();
}

function attempt(callable $call): void
{
    try {
        var_dump($call());
    } catch (Error $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

function main(): void
{
    $child = new DynamicNameChild();
    attempt(fn () => invoke_method($child, 'run'));
    attempt(fn () => invoke_method($child, 'RUN'));
    attempt(fn () => invoke_method($child, 'DynamicNameBase::run'));
    attempt(fn () => invoke_method($child, ['DynamicNameStatic', 'leak']));
    attempt(fn () => invoke_method($child, 'DynamicNameStatic::leak'));
    attempt(fn () => $child->invoke('DynamicNameBase::run'));
    attempt(fn () => $child->invoke(['DynamicNameStatic', 'leak']));
    attempt(fn () => invoke_method($child, 1));

    $magic = new DynamicNameMagic();
    attempt(fn () => invoke_method($magic, 'missing'));
    attempt(fn () => invoke_method($magic, 'DynamicNameBase::run'));
}
?>
--EXPECT--
string(5) "child"
string(5) "child"
Error: Call to undefined method DynamicNameChild::DynamicNameBase::run()
Error: Method name must be a string
Error: Call to undefined method DynamicNameChild::DynamicNameStatic::leak()
Error: Call to undefined method DynamicNameChild::DynamicNameBase::run()
Error: Method name must be a string
Error: Method name must be a string
string(13) "magic:missing"
string(26) "magic:DynamicNameBase::run"

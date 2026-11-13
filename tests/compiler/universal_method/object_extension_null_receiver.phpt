--TEST--
Object extension on a null receiver throws the PHP member-call Error
--FILE--
<?php
class Foo
{
}

#[MethodsFor(Foo::class)]
final class FooExtensions
{
    public static function hello(Foo $foo): string
    {
        return 'hi';
    }
}

function call(bool $null): string
{
    $x = new Foo();
    if ($null) {
        $x = null;
    }
    return $x->hello();
}

function main(): void
{
    echo call(false), "\n";
    try {
        echo call(true), "\n";
    } catch (Error $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
hi
Call to a member function hello() on null

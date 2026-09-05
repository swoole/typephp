--TEST--
dynamic static class target is evaluated before call arguments
--FILE--
<?php

class DynamicClassFirst
{
    public static function render(string $value): string
    {
        return 'first:' . $value;
    }
}

class DynamicClassSecond
{
    public static function render(string $value): string
    {
        return 'second:' . $value;
    }
}

function replace_dynamic_class(string &$class): string
{
    echo "argument\n";
    $class = DynamicClassSecond::class;
    return 'value';
}

function choose_dynamic_class(): string
{
    echo "class\n";
    return DynamicClassFirst::class;
}

function make_dynamic_class_argument(): string
{
    echo "argument\n";
    return 'value';
}

function main(): void
{
    $class = DynamicClassFirst::class;
    var_dump($class::render(replace_dynamic_class($class)));
    var_dump($class::render('next'));
    var_dump(choose_dynamic_class()::render(make_dynamic_class_argument()));
}
?>
--EXPECT--
argument
string(11) "first:value"
string(11) "second:next"
class
argument
string(11) "first:value"

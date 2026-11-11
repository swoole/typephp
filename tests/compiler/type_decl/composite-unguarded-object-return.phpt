--TEST--
Unguarded overlapping object declarations accept valid subtypes and retain runtime return checks
--FILE--
<?php

interface CompositeRuntimeFirst {}
interface CompositeRuntimeSecond {}

class CompositeRuntimeBase {}

class CompositeRuntimeChild extends CompositeRuntimeBase implements CompositeRuntimeFirst, CompositeRuntimeSecond {}

class CompositeRuntimePartial extends CompositeRuntimeBase implements CompositeRuntimeFirst {}

class CompositeRuntimeOnlyFirst implements CompositeRuntimeFirst {}

final class CompositeRuntimeFinal implements CompositeRuntimeFirst {}

function composite_runtime_interface(CompositeRuntimeBase $value): ?CompositeRuntimeFirst
{
    return $value;
}

function composite_runtime_other_interface(CompositeRuntimeFirst $value): ?CompositeRuntimeSecond
{
    return $value;
}

function composite_runtime_child(CompositeRuntimeBase $value): ?CompositeRuntimeChild
{
    return $value;
}

function composite_runtime_final(CompositeRuntimeFirst $value): ?CompositeRuntimeFinal
{
    return $value;
}

function composite_runtime_intersection(CompositeRuntimeBase $value): CompositeRuntimeFirst&CompositeRuntimeSecond
{
    return $value;
}

function main(): void
{
    $child = new CompositeRuntimeChild();
    var_dump(composite_runtime_interface($child) === $child);
    var_dump(composite_runtime_other_interface($child) === $child);
    var_dump(composite_runtime_child($child) === $child);
    $final = new CompositeRuntimeFinal();
    var_dump(composite_runtime_final($final) === $final);
    var_dump(composite_runtime_intersection($child) === $child);

    try {
        composite_runtime_interface(new CompositeRuntimeBase());
    } catch (TypeError $error) {
        echo "base-interface checked\n";
    }
    try {
        composite_runtime_other_interface(new CompositeRuntimeOnlyFirst());
    } catch (TypeError $error) {
        echo "interface-interface checked\n";
    }
    try {
        composite_runtime_child(new CompositeRuntimeBase());
    } catch (TypeError $error) {
        echo "base-child checked\n";
    }
    try {
        composite_runtime_final(new CompositeRuntimeOnlyFirst());
    } catch (TypeError $error) {
        echo "interface-final checked\n";
    }
    try {
        composite_runtime_intersection(new CompositeRuntimeBase());
    } catch (TypeError $error) {
        echo "intersection checked\n";
    }
    try {
        composite_runtime_intersection(new CompositeRuntimePartial());
    } catch (TypeError $error) {
        echo "partial intersection checked\n";
    }
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
base-interface checked
interface-interface checked
base-child checked
interface-final checked
intersection checked
partial intersection checked

--TEST--
Nullable class parameters, returns, locals and properties retain typed objects
--FILE--
<?php
class NullableObjectBase
{
    public function value(): string { return 'base'; }
}
class NullableObjectChild extends NullableObjectBase
{
    public function value(): string { return 'child'; }
}
class NullableObjectHolder
{
    public ?NullableObjectBase $property = null;
    public NullableObjectBase|null $union = null;
    public static ?NullableObjectBase $shared = null;
    public function __construct(public ?NullableObjectBase $promoted = null) {}
}
function nullableObjectIdentity(?NullableObjectBase $value = null): ?NullableObjectBase { return $value; }
function nullableObjectUnion(null|NullableObjectBase $value = null): NullableObjectBase|null { return $value; }
function nullableObjectReturn($value): ?NullableObjectBase { return $value; }
function nullableObjectDynamic($value) { return $value; }
function nullableObjectRequired(NullableObjectBase $value): string { return $value->value(); }
function nullableObjectNonNullReturn(?NullableObjectBase $value): NullableObjectBase { return $value; }
function nullableObjectClear(?NullableObjectBase &$value): void { $value = null; }
function main(): void
{
    var_dump(nullableObjectIdentity(), nullableObjectUnion());
    $object = nullableObjectIdentity(new NullableObjectChild());
    echo $object->value(), "\n";
    $object = null;
    var_dump($object);
    $object = new NullableObjectBase();
    echo nullableObjectRequired($object), "\n";
    $holder = new NullableObjectHolder();
    var_dump($holder->property, $holder->union, $holder->promoted, NullableObjectHolder::$shared);
    $holder->property = nullableObjectUnion(new NullableObjectChild());
    $holder->union = $holder->property;
    NullableObjectHolder::$shared = $holder->union;
    echo $holder->property->value(), ':', NullableObjectHolder::$shared->value(), "\n";
    $holder->property = null;
    $holder->union = null;
    NullableObjectHolder::$shared = null;
    var_dump($holder->property, $holder->union, NullableObjectHolder::$shared);
    $promoted = new NullableObjectHolder(new NullableObjectChild());
    echo $promoted->promoted->value(), "\n";
    unset($holder->property);
    try { var_dump($holder->property); } catch (Error $e) { echo "uninitialized\n"; }
    $holder->property = null;
    var_dump($holder->property);
    $nullable = nullableObjectIdentity();
    try { nullableObjectRequired($nullable); } catch (TypeError $e) { echo "nonnull argument rejected\n"; }
    try { nullableObjectNonNullReturn($nullable); } catch (TypeError $e) { echo "nonnull return rejected\n"; }
    $fn = 'nullableObjectIdentity';
    try { $fn(new stdClass()); } catch (TypeError $e) { echo "wrong argument rejected\n"; }
    try { nullableObjectReturn(nullableObjectDynamic(new stdClass())); } catch (TypeError $e) { echo "wrong return rejected\n"; }
    $ref = nullableObjectDynamic(new NullableObjectBase());
    nullableObjectClear($ref);
    var_dump($ref);
}
?>
--EXPECT--
NULL
NULL
child
NULL
base
NULL
NULL
NULL
NULL
child:child
NULL
NULL
NULL
child
uninitialized
NULL
nonnull argument rejected
nonnull return rejected
wrong argument rejected
wrong return rejected
NULL

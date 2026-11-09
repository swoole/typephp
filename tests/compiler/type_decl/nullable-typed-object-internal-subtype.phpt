--TEST--
Nullable internal base objects dispatch methods declared only by a runtime subtype
--FILE--
<?php
class NullableInternalDate extends DateTime
{
    public function label(): string { return 'child'; }
}
function nullableInternalLabel(?DateTime $value): string
{
    if ($value instanceof NullableInternalDate) {
        return $value->label();
    }
    return 'none';
}
function nullableInternalTypeName(?ReflectionType $type): string
{
    if ($type instanceof ReflectionNamedType) {
        return $type->getName();
    }
    if ($type instanceof ReflectionUnionType) {
        return count($type->getTypes()) . ' types';
    }
    return 'none';
}
function nullableInternalNamed(): ?string { return null; }
function nullableInternalUnion(): int|string { return 1; }
function main(): void
{
    echo nullableInternalLabel(null), "\n";
    echo nullableInternalLabel(new NullableInternalDate()), "\n";
    echo nullableInternalTypeName(null), "\n";
    $named = new ReflectionFunction('nullableInternalNamed');
    echo nullableInternalTypeName($named->getReturnType()), "\n";
    $union = new ReflectionFunction('nullableInternalUnion');
    echo nullableInternalTypeName($union->getReturnType()), "\n";
}
?>
--EXPECT--
none
child
none
string
2 types

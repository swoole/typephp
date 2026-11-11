--TEST--
Instanceof narrows stable branch bindings while preserving subclass dispatch, references and scope boundaries
--FILE--
<?php

final class NarrowingValue { public int $number = 7; public function value(): int { return 7; } }
final class NarrowingOther { public function value(): int { return 9; } }
class NarrowingBase
{
    public function value(): int { return 1; }
    final public function fixed(): int { return 4; }
}
class NarrowingChild extends NarrowingBase { public function value(): int { return 8; } }
function narrowingFinal(mixed $value): int
{
    if ($value instanceof NarrowingValue) { return $value->value(); }
    return 0;
}
function narrowingUnion(NarrowingValue|NarrowingOther $value): int
{
    if ($value instanceof NarrowingValue) { return $value->value(); }
    elseif ($value instanceof NarrowingOther) { return $value->value(); }
    return 0;
}
function narrowingAnd(mixed $value): int
{
    if ($value instanceof NarrowingValue && true) { return $value->value(); }
    return 0;
}
function narrowingRestored(mixed $value): int
{
    $result = 0;
    if ($value instanceof NarrowingValue) { $result = $value->value(); }
    return $result + $value->value();
}
function narrowingBase(mixed $value): int
{
    if ($value instanceof NarrowingBase) { return $value->value(); }
    return 0;
}
function narrowingFixed(mixed $value): int
{
    if ($value instanceof NarrowingBase) { return $value->fixed(); }
    return 0;
}
function narrowingModified(mixed $value): int
{
    if ($value instanceof NarrowingValue) {
        $value = std::any(new NarrowingOther());
        return $value->value();
    }
    return 0;
}
function narrowingReplace(mixed &$value): void { $value = new NarrowingOther(); }
function narrowingAlias(mixed $value): int
{
    $alias =& $value;
    if ($value instanceof NarrowingValue) {
        narrowingReplace($alias);
        return $value->value();
    }
    return 0;
}
function narrowingReference(mixed &$value): int
{
    if ($value instanceof NarrowingValue) { return $value->value(); }
    return 0;
}
function narrowingDynamic(mixed $value, string $class): int
{
    if ($value instanceof $class) { return $value->value(); }
    return 0;
}
function narrowingClosureCapture(mixed $value): int
{
    $replace = static function () use (&$value): void { $value = new NarrowingOther(); };
    if ($value instanceof NarrowingValue) {
        $replace();
        return $value->value();
    }
    return 0;
}
function narrowingChangedCondition(mixed $value): int
{
    if ($value instanceof NarrowingValue && ($value = std::any(new NarrowingOther()))) {
        return $value->value();
    }
    return 0;
}
function narrowingGoto(mixed $value): int
{
    goto inside;
    if ($value instanceof NarrowingValue) {
        inside:
        return $value->value();
    }
    return 0;
}

function narrowingProperty(mixed $value): int
{
    if ($value instanceof NarrowingValue) { return $value->number; }
    return 0;
}
function narrowingClosure(): Closure
{
    return static function (mixed $value): int {
        if ($value instanceof NarrowingValue) { return $value->value(); }
        return 0;
    };
}

function main(): void
{
    $value = new NarrowingValue();
    $other = new NarrowingOther();
    var_dump(narrowingFinal($value), narrowingFinal($other));
    var_dump(narrowingUnion($value), narrowingUnion($other), narrowingAnd($value), narrowingRestored($value));
    var_dump(narrowingBase(new NarrowingChild()), narrowingFixed(new NarrowingChild()));
    var_dump(narrowingModified($value), narrowingAlias($value), narrowingClosureCapture($value));
    var_dump(narrowingChangedCondition($value), narrowingGoto($other));
    $reference = std::any($value);
    var_dump(narrowingReference($reference), narrowingDynamic($value, NarrowingValue::class));
    var_dump(narrowingProperty($value));
    $closure = narrowingClosure();
    var_dump($closure($value), $closure($other));
    try { narrowingRestored(null); }
    catch (Error $error) { echo "outside branch remains dynamic\n"; }
}
?>
--EXPECT--
int(7)
int(0)
int(7)
int(9)
int(7)
int(14)
int(8)
int(4)
int(9)
int(9)
int(9)
int(9)
int(9)
int(7)
int(7)
int(7)
int(7)
int(0)
outside branch remains dynamic

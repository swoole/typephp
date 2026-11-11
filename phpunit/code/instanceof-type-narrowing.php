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

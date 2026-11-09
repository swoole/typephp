<?php

class NullableTypedTarget
{
    public function value(): int { return 42; }
}

class NullableTypedHolder
{
    public ?NullableTypedTarget $target = null;
    public NullableTypedTarget|null $union = null;

    public function __construct(public ?NullableTypedTarget $promoted = null) {}
}

function nullableTypedIdentity(?NullableTypedTarget $target = null): ?NullableTypedTarget
{
    return $target;
}

function nullableTypedUnion(NullableTypedTarget|null $target = null): NullableTypedTarget|null
{
    return $target;
}

function nullableTypedCall(?NullableTypedTarget $target): int
{
    return $target->value();
}

function nullableTypedLocal(): void
{
    $target = nullableTypedIdentity();
    $target = null;
    $target = new NullableTypedTarget();
    $target->value();
}

function nullableTypedReturnNonNull(?NullableTypedTarget $target): NullableTypedTarget
{
    return $target;
}

function nullableTypedReference(?NullableTypedTarget &$target): void
{
    $target = null;
}

function nullableTypedScalar(?int $number): ?int { return $number; }

function nullableTypedOtherUnion(NullableTypedTarget|string $target): NullableTypedTarget|string
{
    return $target;
}

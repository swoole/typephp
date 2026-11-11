<?php

final class SelectionTypeValue
{
    public array $items = [];

    public function __construct(public int $number = 7) {}
    public function value(): int { return $this->number; }
    public function constantValue(): int { return 8; }
}

final class SelectionTypeRoot
{
    public ?SelectionTypeValue $child = null;
    public ?int $number = null;
    public SelectionTypeValue $uninitialized;
    public static ?SelectionTypeValue $shared = null;
    public int $id = 30;
    public function next(): ?SelectionTypeValue { return $this->child; }
    public function count(): int { return 3; }
    public function record(array &$events): int { $events[] = 3; return 3; }
    public function accept(int $value): int { return $this->id + $value; }
}

class SelectionTypeBase
{
    public function count(): int { return 1; }
}

final class SelectionTypeChild extends SelectionTypeBase
{
    public function count(): int { return 2; }
}

function selectionProperty(SelectionTypeRoot $root): int
{
    $object = $root->child ?? new SelectionTypeValue();
    return $object->value();
}

function selectionScalar(?int $number): int { $value = $number ?? 5; return $value; }
function selectionScalarProperty(SelectionTypeRoot $root): int { return $root->number ?? 5; }
function selectionUninitialized(SelectionTypeRoot $root): int { return ($root->uninitialized ?? new SelectionTypeValue(6))->value(); }
function selectionNestedProperty(SelectionTypeRoot $root): int { return $root->child->number ?? 6; }
function selectionNullsafeProperty(?SelectionTypeRoot $root): int { return ($root?->uninitialized ?? new SelectionTypeValue(6))->value(); }
function selectionNullsafeNestedProperty(?SelectionTypeRoot $root): int { return $root?->uninitialized->number ?? 6; }
function selectionNullsafeArrayProbe(?SelectionTypeRoot $root): int { return $root?->uninitialized->items['missing'] ?? 6; }
function selectionNullsafeRequiredMethod(?SelectionTypeRoot $root): int { return $root?->uninitialized->value() ?? 6; }
function selectionNullsafeScalar(?SelectionTypeRoot $root): int { return $root?->count() ?? 0; }
function selectionNullsafeObject(?SelectionTypeRoot $root): int
{
    $object = $root?->next() ?? new SelectionTypeValue();
    return $object->value();
}
function selectionNullsafeLocal(?SelectionTypeRoot $root): mixed
{
    $object = $root?->next();
    return $object?->value();
}
function selectionPolymorphic(?SelectionTypeBase $root): int { return $root?->count() ?? 0; }
function selectionNormalHop(?SelectionTypeRoot $root): mixed { return $root?->next()->constantValue(); }
function selectionReferences(?SelectionTypeRoot $root, array &$events): int { return $root?->record($events) ?? 0; }
function selectionStatic(): int { $object = SelectionTypeRoot::$shared ?? new SelectionTypeValue(); return $object->value(); }
function selectionResetRoot(?SelectionTypeRoot &$root): int { $root = null; return 2; }
function selectionArguments(mixed $root): int { return $root?->accept(selectionResetRoot($root)) ?? 0; }

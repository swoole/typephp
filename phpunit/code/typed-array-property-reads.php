<?php

final class TypedArrayReadItem
{
    public function __construct(public int $number = 7) {}
    public function value(): int { return $this->number; }
}

class TypedArrayReadBox
{
    #[StdList(Type::Int)]
    public array $numbers = [2, 3];

    #[StdList(TypedArrayReadItem::class)]
    public array $items = [];

    #[StdDict(Type::String, Type::Int)]
    public static array $counts = ['3' => 4];
}

#[Native]
class TypedArrayNativeReadBox
{
    #[StdList(Type::Int)]
    public array $numbers = [5, 6];
}

function typedArrayPropertySum(TypedArrayReadBox $box): int
{
    $number = $box->numbers[0];
    foreach ($box->numbers as $index => $value) {
        $number += $index + $value;
    }
    return $number;
}

function typedArrayPropertyObjects(TypedArrayReadBox $box): int
{
    $first = $box->items[0];
    $sum = $first->value();
    foreach ($box->items as $item) {
        $sum += $item->value();
    }
    return $sum;
}

function typedArrayStaticRead(): int
{
    $total = TypedArrayReadBox::$counts['3'];
    foreach (TypedArrayReadBox::$counts as $label => $count) {
        echo $label, ':', $count, "\n";
        $total += $count;
    }
    return $total;
}

function typedArrayNativeRead(TypedArrayNativeReadBox $box): int
{
    $total = $box->numbers[0];
    foreach ($box->numbers as $number) {
        $total += $number;
    }
    return $total;
}

function typedArrayPropertyFallback(TypedArrayReadBox $box, int $key): int
{
    return $box->numbers[$key] ?? 17;
}

function typedArrayReadOwner(TypedArrayReadBox $box, array &$events): TypedArrayReadBox
{
    $events[] = 'owner';
    return $box;
}

function typedArrayReadKey(TypedArrayReadBox $box, array &$events): int
{
    $events[] = 'key';
    $box->numbers = [9];
    return 0;
}

function typedArrayPropertyOrdered(TypedArrayReadBox $box, array &$events): int
{
    return typedArrayReadOwner($box, $events)->numbers[typedArrayReadKey($box, $events)] ?: 17;
}

class TypedArrayReadOuter
{
    public function __construct(public TypedArrayReadBox $box) {}
}

function typedArrayReplaceBox(TypedArrayReadOuter $outer): int
{
    $replacement = new TypedArrayReadBox();
    $replacement->numbers = [11];
    $outer->box = $replacement;
    return 0;
}

function typedArrayNestedPropertyRead(TypedArrayReadOuter $outer): int
{
    return $outer->box->numbers[typedArrayReplaceBox($outer)];
}

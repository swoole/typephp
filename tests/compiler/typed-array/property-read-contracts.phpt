--TEST--
Typed PHP array property reads and foreach recover checked element types
--FILE--
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

function typedArrayPropertyDynamicKey(TypedArrayReadBox $box, any $key): int
{
    return $box->numbers[$key];
}

function main(): void
{
    $box = new TypedArrayReadBox();
    $box->items[] = new TypedArrayReadItem();
    $box->items[] = new TypedArrayReadItem(8);
    var_dump(typedArrayPropertySum($box), typedArrayPropertyObjects($box));
    var_dump(typedArrayStaticRead());
    var_dump(typedArrayNativeRead(new TypedArrayNativeReadBox()));

    $copy = $box->numbers;
    $copy[] = 'plain PHP array';
    var_dump(count($copy), count($box->numbers));
    unset($box->numbers[0]);
    var_dump(isset($box->numbers[0]), typedArrayPropertyFallback($box, 0));

    $events = [];
    var_dump(typedArrayPropertyOrdered($box, $events), $events);

    var_dump(typedArrayNestedPropertyRead(new TypedArrayReadOuter($box)));

    try {
        typedArrayPropertyDynamicKey($box, '0');
    } catch (TypeError $error) {
        echo "key type checked\n";
    }
    $box->numbers = ['invalid'];
    try {
        typedArrayPropertyFallback($box, 0);
    } catch (TypeError $error) {
        echo "selected value checked\n";
    }
    $box->numbers = [0 => 1, 1 => 'invalid'];
    try {
        typedArrayPropertySum($box);
    } catch (TypeError $error) {
        echo "foreach value checked\n";
    }
    $box->items = [new stdClass()];
    try {
        typedArrayPropertyObjects($box);
    } catch (TypeError $error) {
        echo "element class checked\n";
    }
}
?>
--EXPECT--
int(8)
int(22)
3:4
int(8)
int(16)
int(3)
int(2)
bool(false)
int(17)
int(9)
array(2) {
  [0]=>
  string(5) "owner"
  [1]=>
  string(3) "key"
}
int(11)
key type checked
selected value checked
foreach value checked
element class checked

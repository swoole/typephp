--TEST--
Inherited methods take references to private properties in their declaring scope
--FILE--
<?php
class PrivateReferenceBase
{
    private array $items = ['a', 'b', 'c'];

    public function remove(int $index): void
    {
        array_splice($this->items, $index, 1);
    }

    public function pop(): mixed
    {
        return array_pop($this->items);
    }

    public function popNamed(): mixed
    {
        return array_pop(array: $this->items);
    }

    public function popDynamic(string $name): mixed
    {
        return array_pop($this->{$name});
    }

    public function &itemsReference(): array
    {
        return $this->items;
    }

    public function items(): array
    {
        return $this->items;
    }
}

final class PrivateReferenceChild extends PrivateReferenceBase
{
}

function replacePrivateReference(mixed &$value): void
{
    $value = 'wrong type';
}

function main(): void
{
    $base = new PrivateReferenceBase();
    $base->remove(0);
    echo 'base: ', implode(',', $base->items()), "\n";

    $child = new PrivateReferenceChild();
    echo 'pop: ', $child->pop(), "\n";
    $child->remove(0);
    echo 'child: ', implode(',', $child->items()), "\n";

    $child = new PrivateReferenceChild();
    echo 'named: ', $child->popNamed(), "\n";
    echo 'dynamic: ', $child->popDynamic('items'), "\n";
    $alias =& $child->itemsReference();
    $alias[] = 'changed';
    try {
        replacePrivateReference($alias);
    } catch (TypeError $error) {
        echo "type constraint kept\n";
    }
    echo 'alias: ', implode(',', $child->items()), "\n";
}
?>
--EXPECT--
base: b,c
pop: c
child: b
named: c
dynamic: b
type constraint kept
alias: a,changed

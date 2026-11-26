--TEST--
Dynamic reads and writes to inherited private property slots respect the declaring scope
--FILE--
<?php
class DynamicPrivatePropertyBase
{
    private array $items = ['original'];
    private int $count = 1;

    public function read(string $name): mixed
    {
        return $this->{$name};
    }

    public function append(string $name, string $value): void
    {
        $this->{$name}[] = $value;
    }

    public function replaceFirst(string $name, string $value): void
    {
        $this->{$name}[0] = $value;
    }

    public function increment(string $name): void
    {
        $this->{$name} += 2;
    }

    public function items(): array
    {
        return $this->items;
    }
}

final class DynamicPrivatePropertyChild extends DynamicPrivatePropertyBase
{
}

function main(): void
{
    $child = new DynamicPrivatePropertyChild();
    echo implode(',', $child->read('items')), "\n";
    $child->append('items', 'appended');
    $child->replaceFirst('items', 'replaced');
    echo implode(',', $child->items()), "\n";
    $child->increment('count');
    var_dump($child->read('count'));
    var_dump(get_object_vars($child));
}
?>
--EXPECT--
original
replaced,appended
int(3)
array(0) {
}

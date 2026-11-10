--TEST--
Typed PHP array contracts survive value captures, alias captures and generator callbacks
--FILE--
<?php
class TypedCaptureItem
{
    public int $id = 7;
}

function make_typed_reader(#[StdList(Type::Int)] $values): Closure
{
    return function () use ($values): int {
        return $values[0];
    };
}

function main(): void
{
    $values = std::list(Type::Int);
    $values[] = 1;
    $values[] = 2;
    $alias =& $values;
    $dict = std::dict(Type::Str, Type::Int);
    $dict['first'] = 3;

    $ordinary = function () use ($values): int {
        $values[] = 3;
        return count($values);
    };
    $arrow = fn(): int => $values[0];
    $reader = make_typed_reader($values);
    echo 'ordinary=', $ordinary(), ', arrow=', $arrow(), ', reader=', $reader(), "\n";

    $factory = function () use ($alias, $dict) {
        $alias[] = 3;
        $dict['extra'] = 4;
        foreach ($alias as $value) {
            yield $value;
        }
        yield $dict['extra'];
    };
    foreach ($factory() as $value) {
        echo 'yield=', $value, "\n";
    }

    $arrowFactory = fn() => yield $values[1];
    foreach ($arrowFactory() as $value) {
        echo 'arrow-yield=', $value, "\n";
    }

    $items = std::list(TypedCaptureItem::class);
    $items[] = new TypedCaptureItem();
    $objects = function () use ($items) {
        foreach ($items as $item) {
            yield $item->id;
        }
    };
    foreach ($objects() as $value) {
        echo 'object-yield=', $value, "\n";
    }
    echo 'outer=', count($values), ',', count($dict), ',', count($items), "\n";
}
?>
--EXPECT--
ordinary=3, arrow=1, reader=1
yield=1
yield=2
yield=3
yield=4
arrow-yield=2
object-yield=7
outer=2,1,1

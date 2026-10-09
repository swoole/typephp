--TEST--
ArrayAccess writes through temporary property receivers succeed and release the receivers
--FILE--
<?php

class TemporaryWriteBag implements ArrayAccess
{
    public mixed $value = 10;

    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    public function offsetGet(mixed $offset): mixed
    {
        echo 'G';
        return $this->value;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        echo 'S';
        $this->value = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}

class TemporaryWriteHolder
{
    public mixed $bag;
    public static int $destroyed = 0;

    public function __construct(ArrayAccess $bag)
    {
        echo 'H';
        $this->bag = $bag;
    }

    public function __destruct()
    {
        ++self::$destroyed;
    }
}

function temporaryWriteHolder(ArrayAccess $bag): TemporaryWriteHolder
{
    return new TemporaryWriteHolder($bag);
}

function temporaryWriteKey(): int
{
    echo 'K';
    return 0;
}

function temporaryWriteValue(): int
{
    echo 'V';
    return 2;
}

function main(): void
{
    $bag = new TemporaryWriteBag();
    $result = (temporaryWriteHolder($bag)->bag[temporaryWriteKey()] = temporaryWriteValue());
    echo "\n";
    var_dump($result, $bag->value, TemporaryWriteHolder::$destroyed);

    $compound = (temporaryWriteHolder($bag)->bag[temporaryWriteKey()] += temporaryWriteValue());
    echo "\n";
    var_dump($compound, $bag->value, TemporaryWriteHolder::$destroyed);
}
?>
--EXPECT--
HKVS
int(2)
int(2)
int(1)
HKVGS
int(4)
int(4)
int(2)

--TEST--
ArrayAccess compound operators call offsetGet and offsetSet once and return the written value
--FILE--
<?php

class CompoundOffsetBag implements ArrayAccess
{
    public mixed $value = 12;

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

class CompoundOffsetHolder
{
    public ArrayAccess $bag;
    public mixed $dynamic;
    public static mixed $shared;

    public function __construct()
    {
        $this->bag = new CompoundOffsetBag();
        $this->dynamic = $this->bag;
    }
}

function updateOffsets(ArrayAccess $bag): void
{
    var_dump($bag[0] += 3);
    var_dump($bag[0] -= 5);
    var_dump($bag[0] *= 3);
    var_dump($bag[0] /= 4);
    var_dump($bag[0] = 7);
    var_dump($bag[0] %= 4);
    var_dump($bag[0] **= 3);
    var_dump($bag[0] <<= 2);
    var_dump($bag[0] >>= 1);
    $and = ($bag[0] &= 6);
    var_dump($and, is_int($and));
    $or = ($bag[0] |= 8);
    var_dump($or, is_int($or));
    $xor = ($bag[0] ^= 3);
    var_dump($xor, is_int($xor));
    var_dump($bag[0] .= '!');
}

function dynamicOffsets(mixed $bag): mixed
{
    return $bag;
}

function referenceOffsets(mixed &$bag): void
{
    var_dump($bag[0] = 20);
    var_dump($bag[0] /= 8);
}

function main(): void
{
    $bag = new CompoundOffsetBag();
    updateOffsets($bag);
    var_dump($bag->value);

    $holder = new CompoundOffsetHolder();
    var_dump($holder->bag[0] += 3);
    var_dump($holder->dynamic[0] /= 2);
    CompoundOffsetHolder::$shared = $holder->bag;
    var_dump(CompoundOffsetHolder::$shared[0] *= 2);
    $dynamic = dynamicOffsets($holder->bag);
    var_dump($dynamic[0] -= 5);
    referenceOffsets($dynamic);
    var_dump($holder->bag->value);

    var_dump($holder->dynamic[] = 30);
    var_dump(CompoundOffsetHolder::$shared[] = 40);
    var_dump($dynamic[] = 50);
    var_dump($bag[] = 60);
}
?>
--EXPECT--
GSint(15)
GSint(10)
GSint(30)
GSfloat(7.5)
Sint(7)
GSint(3)
GSint(27)
GSint(108)
GSint(54)
GSint(6)
bool(true)
GSint(14)
bool(true)
GSint(13)
bool(true)
GSstring(3) "13!"
string(3) "13!"
GSint(15)
GSfloat(7.5)
GSfloat(15)
GSfloat(10)
Sint(20)
GSfloat(2.5)
float(2.5)
Sint(30)
Sint(40)
Sint(50)
Sint(60)

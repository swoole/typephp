--TEST--
ArrayAccess writes evaluate receiver, key and RHS once, preserve key types and do not write on errors
--FILE--
<?php

class OrderedOffsetBag implements ArrayAccess
{
    public mixed $value = 10;
    public bool $failRead = false;

    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    public function offsetGet(mixed $offset): mixed
    {
        echo 'G:' . get_debug_type($offset) . ';';
        if ($this->failRead) {
            throw new RuntimeException('read failed');
        }
        return $this->value;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        echo 'S:' . get_debug_type($offset) . ';';
        $this->value = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}

class OrderedOffsetHolder
{
    public mixed $bag;

    public function __construct()
    {
        $this->bag = new OrderedOffsetBag();
    }
}

function offsetReceiver(OrderedOffsetHolder $holder): OrderedOffsetHolder
{
    echo 'R;';
    return $holder;
}

function offsetKey(): string
{
    echo 'K;';
    return 'key';
}

function offsetValue(): int
{
    echo 'V;';
    return 2;
}

function offsetContainer(OrderedOffsetHolder $holder): mixed
{
    echo 'C;';
    return $holder->bag;
}

function main(): void
{
    $holder = new OrderedOffsetHolder();
    var_dump(offsetReceiver($holder)->bag[offsetKey()] = offsetValue());
    var_dump(offsetReceiver($holder)->bag[offsetKey()] = offsetReceiver($holder)->bag[offsetKey()] = offsetValue());
    var_dump(offsetReceiver($holder)->bag[offsetKey()] += offsetValue());
    var_dump(offsetContainer($holder)[offsetKey()] *= offsetValue());
    $property = 'bag';
    var_dump(offsetReceiver($holder)->{$property}[offsetKey()] /= offsetValue());

    var_dump($holder->bag[true] += 1);
    var_dump($holder->bag[1.5] *= 2);
    var_dump($holder->bag[null] -= 1);

    false && ($holder->bag[0] = offsetValue());
    false && ($holder->bag[0] += offsetValue());
    var_dump($holder->bag->value);

    $typephp_container = 20;
    $typephp_key = 'named';
    $typephp_value = 30;
    $typephp_current = 40;
    var_dump($holder->bag[$typephp_key] = $typephp_value);
    var_dump($holder->bag[$typephp_key] += $typephp_current);
    var_dump($typephp_container, $typephp_key, $typephp_value, $typephp_current);

    $zero = 0;
    try {
        $holder->bag[0] /= $zero;
    } catch (DivisionByZeroError $error) {
        echo "divide failed\n";
    }
    var_dump($holder->bag->value);
    $holder->bag->failRead = true;
    try {
        $holder->bag[offsetKey()] += offsetValue();
    } catch (Throwable $error) {
        echo "read failed\n";
    }
    var_dump($holder->bag->value);
}
?>
--EXPECT--
R;K;V;S:string;int(2)
R;K;R;K;V;S:string;S:string;int(2)
R;K;V;G:string;S:string;int(4)
C;K;V;G:string;S:string;int(8)
R;K;V;G:string;S:string;int(4)
G:bool;S:bool;int(5)
G:float;S:float;int(10)
G:null;S:null;int(9)
int(9)
S:string;int(30)
G:string;S:string;int(70)
int(20)
string(5) "named"
int(30)
int(40)
G:int;divide failed
int(70)
K;V;G:string;read failed
int(70)

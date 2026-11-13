--TEST--
A TypePHP class may implement keyword methods with the exact keyword signature
--FILE--
<?php

class Money
{
    public int $cents = 1250;

    public function toInt(): int
    {
        return $this->cents;
    }

    public function toFloat(): float
    {
        return $this->cents / 100.0;
    }

    public function toString(): string
    {
        return 'USD ' . $this->cents;
    }

    public function toBool(): bool
    {
        return $this->cents > 0;
    }

    public function toArray(): array
    {
        return ['cents' => $this->cents];
    }
}

class Discount extends Money
{
    public function toInt(): int
    {
        return -1;
    }
}

class Legacy
{
    public function __toString(): string
    {
        return 'legacy';
    }
}

class Both
{
    public function toString(): string
    {
        return 'keyword';
    }

    public function __toString(): string
    {
        return 'magic';
    }
}

function main(): void
{
    $money = new Money();
    echo $money->toInt(), "\n";
    echo $money->TOFLOAT(), "\n";
    echo $money->toString(), "\n";
    echo var_export($money->toBool(), true), "\n";
    echo json_encode($money->toArray()), "\n";

    $discount = new Discount();
    echo $discount->toInt(), ' ', $discount->toString(), "\n";

    echo (new Legacy())->toString(), "\n";
    echo (new Both())->toString(), "\n";

    $dyn = std::any(new Money());
    echo $dyn->toInt(), ' ', $dyn->toString(), ' ', var_export($dyn->toBool(), true), "\n";
    $dyn = std::any(new Discount());
    echo $dyn->toInt(), "\n";
    $dyn = std::any('42');
    echo $dyn->toInt() + 1, ' ', $dyn->toFloat(), "\n";
    $dyn = std::any(null);
    echo $dyn->toInt(), '|', $dyn->toString(), '|', var_export($dyn->toBool(), true), "\n";
    $dyn = std::any(new Legacy());
    echo $dyn->toString(), "\n";
}
?>
--EXPECT--
1250
12.5
USD 1250
true
{"cents":1250}
-1 USD 1250
legacy
keyword
1250 USD 1250 true
-1
43 42
0||false
legacy

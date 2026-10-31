<?php

namespace Shop;

interface Priced
{
    public function price(): int;
}

trait HasSku
{
    public function sku(): string
    {
        return 'sku:generic';
    }
}

#[Stamp]
trait Countable
{
    public function count(): int
    {
        return 0;
    }
}

#[Described]
abstract class Document implements Priced
{
    public int $pages = 1;

    abstract public function price(): int;
}

#[Described]
final class Receipt extends Document
{
    public function price(): int
    {
        return 10 * $this->pages;
    }
}

#[Described]
final readonly class Money
{
    public function __construct(public int $amount)
    {
    }
}

#[Entity]
#[Audited]
#[Described]
final class Order implements Priced
{
    #[Clamped(0, 1000)]
    public int $total = 0;

    #[Trimmed]
    public string $customer = 'guest';

    #[Surcharge(fee: 10)]
    public int $net = 100;

    #[Derived('$this->total * 2')]
    public int $doubleTotal;

    public function price(): int
    {
        return $this->total;
    }

    #[Traced]
    private static function internal(): void
    {
    }

    #[Traced]
    public function add(int $amount, int &$seen = 0, int ...$extra): int
    {
        $seen = $seen + 1;
        $this->total = $this->total + $amount + count($extra);

        return $this->total;
    }
}

final class Bag
{
    use Countable;
}

#[Labelled('order')]
enum State: string
{
    case Open = 'open';
    case Closed = 'closed';
}

#[Labelled]
enum Priority: int
{
    case Low = 1;
    case High = 2;
}

<?php

namespace ChainType;

class Column
{
    private array $opts = [];

    public static function make(): self
    {
        return new self();
    }

    public function gap(int $value): self
    {
        $this->opts['gap'] = $value;
        return $this;
    }

    public function padding(int $value): self
    {
        $this->opts['padding'] = $value;
        return $this;
    }

    public function describe(): string
    {
        return 'gap=' . $this->opts['gap'] . ' padding=' . $this->opts['padding'];
    }

    public function styled(): Styled
    {
        return new Style();
    }

    public function run(): string
    {
        return Column::make()->gap(12)->padding(16)->describe();
    }
}

class StaticColumn
{
    private int $total = 0;

    public static function make(): static
    {
        return new static();
    }

    public function add(int $value): static
    {
        $this->total += $value;
        return $this;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function runStatic(): int
    {
        return StaticColumn::make()->add(1)->add(2)->total();
    }
}

interface Styled
{
    public function color(int $value): self;

    public function border(): Border;
}

final class Style implements Styled
{
    public function color(int $value): self
    {
        return $this;
    }

    public function border(): Border
    {
        return new Border();
    }
}

class Border
{
    private int $width = 0;

    public function width(int $value): self
    {
        $this->width = $value;
        return $this;
    }

    public function describe(): string
    {
        return 'width=' . $this->width;
    }
}

class ColumnStyles
{
    public function runStyled(): string
    {
        return Column::make()->styled()->border()->width(9)->describe();
    }
}

class BaseFactory
{
    public static function make(): BaseFactory
    {
        return new ChildFactory();
    }

    public function render(): string
    {
        return 'base';
    }

    final public function sealed(): string
    {
        return 'sealed';
    }
}

class ChildFactory extends BaseFactory
{
    public function render(): string
    {
        return 'child';
    }
}

class PolymorphicChain
{
    public function runPolymorphic(): string
    {
        return BaseFactory::make()->render() . BaseFactory::make()->sealed();
    }
}

class StaticFactory
{
    public static function make(): static
    {
        return new LsbSubFactory();
    }

    public function label(): string
    {
        return 'base';
    }
}

class LsbSubFactory extends StaticFactory
{
}

class LsbHolder
{
    public LsbSubFactory $prop;

    public function runLsb(): string
    {
        $this->prop = LsbSubFactory::make();
        return $this->prop->label();
    }
}

class DeepChain
{
    private int $depth = 0;

    public static function make(): self
    {
        return new self();
    }

    public function m1(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m2(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m3(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m4(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m5(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m6(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m7(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m8(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m9(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m10(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m11(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m12(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m13(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m14(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m15(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m16(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m17(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function m18(int $v): self
    {
        $this->depth += $v;
        return $this;
    }

    public function runDeep(): int
    {
        return DeepChain::make()
            ->m1(1)
            ->m2(2)
            ->m3(3)
            ->m4(4)
            ->m5(5)
            ->m6(6)
            ->m7(7)
            ->m8(8)
            ->m9(9)
            ->m10(10)
            ->m11(11)
            ->m12(12)
            ->m13(13)
            ->m14(14)
            ->m15(15)
            ->m16(16)
            ->m17(17)
            ->m18(18)
            ->m1(19)
            ->depth();
    }

    public function depth(): int
    {
        return $this->depth;
    }
}

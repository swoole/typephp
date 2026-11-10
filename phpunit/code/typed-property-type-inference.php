<?php

namespace PropertyInference;

interface StyleContract
{
    public function sum(): int;
    public function withSum(int $extra): Style;
}

final class Style implements StyleContract
{
    public function __construct(public int $value = 5) {}
    public function sum(): int { return $this->value; }
    public function withSum(int $extra): Style { return new Style($this->value + $extra); }
    public function record(array &$events): void { $events[] = $this->value; }
}

class BaseStyle
{
    public function sum(): int { return 1; }
}

final class ChildStyle extends BaseStyle
{
    public function sum(): int { return 2; }
}

class Holder
{
    private Style $style;
    protected Style $inherited;
    public Style $publicStyle;
    public ?Style $nullable = null;
    public StyleContract $contract;
    public BaseStyle $polymorphic;
    public Style $uninitialized;

    public function __construct()
    {
        $this->style = new Style();
        $this->inherited = new Style(6);
        $this->publicStyle = $this->style;
        $this->contract = $this->style;
        $this->polymorphic = new ChildStyle();
    }

    public function local(): int { $style = $this->style; return $style->sum(); }
    public function returned(): int { $style = $this->style->withSum(1); return $style->sum(); }
    public function cloned(): int { $style = clone $this->style; return $style->sum(); }
    public function maybe(): int { $style = $this->nullable; return $style->sum(); }
    public function viaContract(): int { $style = $this->contract; return $style->withSum(2)->sum(); }
    public function choose(bool $flag): int { return $flag ? $this->contract->sum() : 0; }
    public function virtual(): int { $style = $this->polymorphic; return $style->sum(); }
    public function missing(): int { $style = $this->uninitialized; return $style->sum(); }
}

final class ChildHolder extends Holder
{
    public function inherited(): int { $style = $this->inherited; return $style->sum(); }
}

final class Envelope
{
    public MagicBox $magic;
    public function __construct(public Holder $holder) { $this->magic = new MagicBox(); }
}

final class MagicBox
{
    private Style $target;
    private Style $number;
    public function __get(string $name): mixed
    {
        if ($name === 'number') { return 7; }
        return new Style(9);
    }
}

function fromParameter(Holder $holder): int { $style = $holder->publicStyle; return $style->sum(); }
function nested(Envelope $envelope): int { $style = $envelope->holder->publicStyle; return $style->sum(); }
function byReference(Holder $holder, array &$events): void { $style = $holder->publicStyle; $style->record($events); }
function magicProperty(Envelope $envelope): int { $style = $envelope->magic->target; return $style->sum(); }
function magicNumber(Envelope $envelope): int { $value = $envelope->magic->number; return $value; }

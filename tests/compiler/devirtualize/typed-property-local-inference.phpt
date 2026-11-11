--TEST--
Typed property locals preserve class contracts, references, polymorphism and read errors
--FILE--
<?php

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
    public function __construct(public Holder $holder) {}
}

function fromParameter(Holder $holder): int { $style = $holder->publicStyle; return $style->sum(); }
function nested(Envelope $envelope): int { $style = $envelope->holder->publicStyle; return $style->sum(); }
function byReference(Holder $holder, array &$events): void { $style = $holder->publicStyle; $style->record($events); }

function main(): void
{
    $holder = new ChildHolder();
    $envelope = new Envelope($holder);
    echo $holder->local(), ':', $holder->returned(), ':', $holder->cloned(), ':', $holder->inherited(), "\n";
    echo fromParameter($holder), ':', nested($envelope), ':', $holder->viaContract(), ':', $holder->virtual(), "\n";
    echo $holder->choose(true), ':', $holder->choose(false), "\n";
    $events = [];
    byReference($holder, $events);
    echo json_encode($events, JSON_THROW_ON_ERROR), "\n";
    $holder->nullable = new Style(8);
    echo $holder->maybe(), "\n";
    $holder->nullable = null;
    try {
        $holder->maybe();
        echo "unexpected-null\n";
    } catch (\Error $error) {
        echo "null-error\n";
    }
    try {
        $holder->missing();
        echo "unexpected-uninitialized\n";
    } catch (\Error $error) {
        echo "uninitialized-error\n";
    }
    unset($holder->publicStyle);
    try {
        fromParameter($holder);
        echo "unexpected-unset\n";
    } catch (\Error $error) {
        echo "unset-error\n";
    }
}
?>
--EXPECT--
5:6:5:6
5:5:7:2
5:0
[5]
8
null-error
uninitialized-error
unset-error

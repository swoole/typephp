--TEST--
Abstract, interface and plain class declarations keep nullable interface returns runtime-checked
--FILE--
<?php

interface CompositeScrollMetrics
{
    public function offset(): float;
}

abstract class CompositeState
{
}

class CompositeScrollState extends CompositeState implements CompositeScrollMetrics
{
    public function offset(): float
    {
        return 0.5;
    }
}

class CompositePlainState extends CompositeState
{
}

function composite_state(bool $scrollable): CompositeState
{
    if ($scrollable) {
        return new CompositeScrollState();
    }
    return new CompositePlainState();
}

function composite_target(CompositeState $state): ?CompositeScrollMetrics
{
    if ($state instanceof CompositeScrollMetrics) {
        return $state;
    }
    return null;
}

interface CompositeReadable
{
    public function read(): string;
}

interface CompositeWritable
{
    public function label(): string;
}

class CompositeReadWrite implements CompositeReadable, CompositeWritable
{
    public function read(): string
    {
        return 'rw';
    }

    public function label(): string
    {
        return 'writable';
    }
}

function composite_writable(CompositeReadable $value): ?CompositeWritable
{
    if ($value instanceof CompositeWritable) {
        return $value;
    }
    return null;
}

interface CompositeTickable
{
    public function tick(): int;
}

class CompositeBaseBox
{
}

class CompositeTickBox extends CompositeBaseBox implements CompositeTickable
{
    public function tick(): int
    {
        return 42;
    }
}

function composite_tickable(CompositeBaseBox $box): ?CompositeTickable
{
    if ($box instanceof CompositeTickable) {
        return $box;
    }
    return null;
}

function main(): void
{
    $scroll = composite_target(composite_state(true));
    var_dump($scroll !== null);
    if ($scroll !== null) {
        echo $scroll->offset(), "\n";
    }
    var_dump(composite_target(composite_state(false)));

    $writable = composite_writable(new CompositeReadWrite());
    var_dump($writable instanceof CompositeWritable);
    if ($writable !== null) {
        echo $writable->label(), "\n";
    }

    $tick = composite_tickable(new CompositeTickBox());
    var_dump($tick !== null);
    if ($tick !== null) {
        echo $tick->tick(), "\n";
    }
    var_dump(composite_tickable(new CompositeBaseBox()));
}
?>
--EXPECT--
bool(true)
0.5
NULL
bool(true)
writable
bool(true)
42
NULL

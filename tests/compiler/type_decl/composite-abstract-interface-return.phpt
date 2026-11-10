--TEST--
Abstract and interface declarations keep nullable interface returns runtime-checked
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
}
?>
--EXPECT--
bool(true)
0.5
NULL
bool(true)
writable

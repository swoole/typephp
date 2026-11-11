<?php

interface CompositeIterableLike
{
    public function next(): void;
}

class CompositeBaseBox
{
    public function value(): int
    {
        return 1;
    }
}

class CompositeChildBox extends CompositeBaseBox implements CompositeIterableLike
{
    public function next(): void
    {
    }
}

function composite_target(CompositeBaseBox $value): ?CompositeIterableLike
{
    if ($value instanceof CompositeIterableLike) {
        return $value;
    }
    return null;
}

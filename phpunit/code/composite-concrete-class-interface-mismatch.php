<?php

interface CompositeIteratorLike
{
    public function next(): void;
}

class CompositePlainBox
{
    public function value(): int
    {
        return 1;
    }
}

function composite_wrong_return(CompositePlainBox $box): ?CompositeIteratorLike
{
    if ($box instanceof CompositeIteratorLike) {
        return $box;
    }
    return null;
}

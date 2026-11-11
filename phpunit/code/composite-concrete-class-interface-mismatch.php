<?php

interface CompositeIteratorLike
{
    public function next(): void;
}

final class CompositePlainBox
{
    public function value(): int
    {
        return 1;
    }
}

function composite_wrong_return(CompositePlainBox $box): ?CompositeIteratorLike
{
    return $box;
}

<?php

interface CompositeTickLike
{
    public function tick(): void;
}

function composite_wrong_closure(Closure $value): ?CompositeTickLike
{
    return $value;
}

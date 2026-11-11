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

function composite_target(CompositeState $state): ?CompositeScrollMetrics
{
    if ($state instanceof CompositeScrollMetrics) {
        return $state;
    }
    return null;
}

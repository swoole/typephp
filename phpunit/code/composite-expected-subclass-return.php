<?php

class CompositeParentBox
{
}

class CompositeChildBox extends CompositeParentBox
{
}

function composite_maybe_child(CompositeParentBox $value): ?CompositeChildBox
{
    return $value;
}

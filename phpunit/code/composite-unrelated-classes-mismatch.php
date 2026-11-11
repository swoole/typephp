<?php

class CompositeFirstBox
{
}

class CompositeSecondBox
{
}

function composite_wrong_unrelated(CompositeFirstBox $value): ?CompositeSecondBox
{
    return $value;
}

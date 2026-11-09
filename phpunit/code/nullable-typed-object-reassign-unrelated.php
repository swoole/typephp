<?php

class NullableReassignTarget {}
class NullableReassignOther {}

function nullableReassignFactory(): ?NullableReassignTarget
{
    return null;
}

function nullableReassignUnrelated(): void
{
    $object = nullableReassignFactory();
    $object = new NullableReassignOther();
}

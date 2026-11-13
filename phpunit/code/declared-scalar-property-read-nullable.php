<?php

final class DeclaredScalarNullableBox
{
    public ?int $count = null;
}

function declared_scalar_read_nullable(DeclaredScalarNullableBox $box): ?int
{
    return $box->count;
}

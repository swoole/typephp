<?php

final class DeclaredScalarReadonlyBox
{
    public function __construct(public readonly int $count)
    {
    }
}

function declared_scalar_read_readonly(DeclaredScalarReadonlyBox $box): int
{
    return $box->count;
}

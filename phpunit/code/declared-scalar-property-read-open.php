<?php

class DeclaredScalarOpenBox
{
    public int $count = 0;
}

function declared_scalar_read_open(DeclaredScalarOpenBox $box): int
{
    return $box->count;
}

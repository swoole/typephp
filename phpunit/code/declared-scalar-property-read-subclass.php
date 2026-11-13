<?php

class DeclaredScalarSubclassBase
{
    public int $count = 1;
}

class DeclaredScalarSubclassChild extends DeclaredScalarSubclassBase
{
    public int $count = 2;
}

function declared_scalar_read_subclass(DeclaredScalarSubclassBase $box): int
{
    return $box->count;
}

<?php

class DeclaredScalarHookedBase
{
    public int $count = 1;
}

class DeclaredScalarHookedChild extends DeclaredScalarHookedBase
{
    public int $count = 2 {
        get => 42;
    }
}

function declared_scalar_read_hooked(DeclaredScalarHookedBase $box): int
{
    return $box->count;
}

<?php

final class DeclaredScalarNarrowedBox
{
    public int $count = 0;
}

function declared_scalar_read_narrowed(mixed $value): int
{
    if ($value instanceof DeclaredScalarNarrowedBox) {
        return $value->count;
    }
    return -1;
}

<?php

final class DeclaredScalarFinalBox
{
    public int $count = 0;
    public float $ratio = 0.0;

    public function scaled(): float
    {
        return $this->ratio * $this->count;
    }
}

function declared_scalar_read_final(DeclaredScalarFinalBox $box): float
{
    return $box->ratio + $box->count;
}

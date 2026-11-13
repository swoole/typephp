<?php

final readonly class DeclaredScalarReadonlyArith
{
    public function __construct(
        public float $width,
        public float $height,
    ) {
    }
}

function declared_scalar_readonly_arith(DeclaredScalarReadonlyArith $box): float
{
    return $box->width < $box->height ? $box->width / 2.0 : $box->height / 2.0;
}

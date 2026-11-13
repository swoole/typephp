<?php

final class DeclaredScalarLoopBox
{
    public int $count = 1;

    public function sum(int $iterations): int
    {
        $total = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $total += $this->count;
        }
        return $total;
    }
}

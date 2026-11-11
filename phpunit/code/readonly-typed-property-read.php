<?php

final class ReadonlyTypedState
{
    public function __construct(
        private readonly int $status = 0,
        private readonly float $ratio = 1.0,
    ) {
    }

    public function status(): int
    {
        switch ($this->status) {
            case 0:
                return 10;
            case 1:
                return 20;
            default:
                return 30;
        }
    }

    public function ratio(): float
    {
        return $this->ratio;
    }
}

function main(): void
{
    $state = new ReadonlyTypedState(1, 0.5);
    echo $state->status(), "\n";
    echo $state->ratio(), "\n";
}

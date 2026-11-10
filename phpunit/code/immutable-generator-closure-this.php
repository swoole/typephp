<?php

class ImmutableGeneratorClosureThis
{
    public int $value = 1;

    #[Immutable]
    public function callback(): Closure
    {
        return function (): Generator {
            yield 1;
            ++$this->value;
        };
    }
}

<?php

function immutableGeneratorClosureParameter(): Closure
{
    return function (#[Immutable] int $value): Generator {
        yield 1;
        ++$value;
    };
}

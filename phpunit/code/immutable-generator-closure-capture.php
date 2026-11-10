<?php

function immutableGeneratorClosureCapture(#[Immutable] object $value): Closure
{
    return function () use ($value): Generator {
        yield 1;
        $value = null;
    };
}

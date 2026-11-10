<?php

function immutableGeneratorNestedClosure(#[Immutable] array $values): Closure
{
    return function () use ($values): Generator {
        yield function () use ($values): void {
            $values[] = 2;
        };
    };
}

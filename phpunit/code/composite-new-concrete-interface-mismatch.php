<?php

interface CompositeRunnableLike
{
    public function run(): void;
}

class CompositePlainRunner
{
}

function composite_wrong_new_return(): ?CompositeRunnableLike
{
    return new CompositePlainRunner();
}

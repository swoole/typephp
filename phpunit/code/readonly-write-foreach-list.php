<?php

class ReadonlyForeachListWrite
{
    public readonly int $value;

    public function __construct()
    {
        foreach ([[1]] as [$this->value]) {
        }
    }
}

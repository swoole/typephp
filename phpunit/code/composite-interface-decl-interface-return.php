<?php

interface CompositeReadable
{
    public function read(): string;
}

interface CompositeWritable
{
    public function write(string $value): void;
}

function composite_write_if_readable(CompositeReadable $value): ?CompositeWritable
{
    if ($value instanceof CompositeWritable) {
        return $value;
    }
    return null;
}

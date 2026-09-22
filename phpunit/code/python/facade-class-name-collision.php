<?php

class PyObject
{
    public function toValue(): int
    {
        return 42;
    }
}

class PyList extends PyObject
{
    public function toArray(): array
    {
        return [1, 2];
    }
}

function main(): void
{
    $value = new PyObject();
    $list = new PyList();
    var_dump($value->toValue(), $list->toArray());
}

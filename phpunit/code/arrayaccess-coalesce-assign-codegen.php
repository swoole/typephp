<?php

class CodegenArrayAccessBag implements ArrayAccess
{
    public function offsetExists(mixed $offset): bool { return false; }
    public function offsetGet(mixed $offset): mixed { return null; }
    public function offsetSet(mixed $offset, mixed $value): void {}
    public function offsetUnset(mixed $offset): void {}
}

class CodegenArrayAccessHolder
{
    public function __construct(public mixed $value) {}

    public function __get(string $name): mixed
    {
        return $this->value;
    }
}

function coalesceArrayAccess(CodegenArrayAccessBag $bag, string $key): mixed
{
    return $bag[$key] ??= 42;
}

function coalesceMixedArrayAccess(mixed &$container, string $key): mixed
{
    return $container[$key] ??= 43;
}

function coalesceMagicArrayAccess(CodegenArrayAccessHolder $holder, string $key): mixed
{
    return $holder->virtual[$key] ??= 44;
}

function coalesceFixedArray(string $key): mixed
{
    $container = [];
    return $container[$key] ??= 45;
}

function replaceCodegenArray(mixed &$container, string &$key): int
{
    $container = new CodegenArrayAccessBag();
    $key = 'replacement';
    return 46;
}

function coalesceMutableFixedArray(string $key): mixed
{
    $container = [];
    return $container[$key] ??= replaceCodegenArray($container, $key);
}

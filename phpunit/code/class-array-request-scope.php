<?php

interface RequestArrayContract
{
    public const CONTRACT = ['interface'];
}

class RequestArrayParent
{
    public const DATA = ['name' => 'parent', 'nested' => [1, 1.5, '01']];
}

class RequestArrayChild extends RequestArrayParent implements RequestArrayContract
{
    public const OWN = ['child'];
}

function readRequestArrays(): array
{
    return [RequestArrayParent::DATA, RequestArrayChild::DATA, RequestArrayChild::CONTRACT,
        RequestArrayChild::OWN];
}

function main(): int
{
    return count(readRequestArrays());
}

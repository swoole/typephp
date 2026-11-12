<?php

declare(strict_types=1);

final class ProvenExpressionStorage
{
    public string $text = 'property';
    public readonly int $number;

    public function text(): string { return 'method'; }

    public function methodChoice(bool $choose): string
    {
        return $choose ? $this->text() : 'literal';
    }
}

function storedStringChoice(bool $choose, string $text): string
{
    return $choose ? $text : 'literal';
}

function storedFunctionResult(): string { return 'function'; }

function storedCallChoice(bool $choose): string
{
    return $choose ? storedFunctionResult() : 'literal';
}

function storedMatchChoice(int $choose, string $text): string
{
    return match ($choose) { 0 => $text, default => 'literal' };
}

function dynamicPropertyChoice(bool $choose, ProvenExpressionStorage $object): string
{
    return $choose ? $object->text : 'literal';
}

function readonlyNumberChoice(bool $choose, ProvenExpressionStorage $object): int
{
    return $choose ? $object->number : 7;
}

function dynamicResultChoice(bool $choose, callable $operation): string
{
    return $choose ? $operation() : 'literal';
}

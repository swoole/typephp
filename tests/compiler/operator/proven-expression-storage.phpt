--TEST--
Proven string storage keeps selection lazy and preserves dynamic return and property checks
--FILE--
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

function main(): void {
    $probe = new ProvenExpressionStorage();
    echo storedStringChoice(true, 'typed'), ':', storedStringChoice(false, 'typed'), "\n";
    echo storedCallChoice(true), ':', storedCallChoice(false), "\n";
    echo $probe->methodChoice(true), ':', $probe->methodChoice(false), "\n";
    echo storedMatchChoice(0, 'typed'), ':', storedMatchChoice(1, 'typed'), "\n";
    echo dynamicPropertyChoice(true, $probe), "\n";
    echo dynamicPropertyChoice(false, $probe), "\n";
    echo readonlyNumberChoice(false, $probe), "\n";
    try { readonlyNumberChoice(true, $probe); }
    catch (\Error $error) { echo 'uninitialized', "\n"; }
    $good = static function (): mixed { return 'dynamic'; };
    $bad = static function (): mixed { return 42; };
    echo dynamicResultChoice(true, $good), ':', dynamicResultChoice(false, $bad), "\n";
    try { dynamicResultChoice(true, $bad); }
    catch (\TypeError $error) { echo 'return type', "\n"; }
}

?>
--EXPECT--
typed:literal
function:literal
method:literal
typed:literal
property
literal
7
uninitialized
dynamic:literal
return type

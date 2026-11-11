<?php

final class LiteralArrayValue
{
    public function __construct(public int $number = 7) {}
    public function value(): int { return $this->number; }
}

function literalArrayRead(): int
{
    $array = [new LiteralArrayValue()];
    $value = $array[0];
    return $value->value();
}

function literalArrayDestructure(): int
{
    [$value, $count, ['name' => $text]] = [new LiteralArrayValue(), 3, ['name' => 'abc']];
    return $value->value() + $count + strlen($text);
}

function literalArrayKnownKeys(): int
{
    $array = ['1' => new LiteralArrayValue(), 'count' => 3, 'count' => 4];
    $value = $array[1];
    $count = $array['count'];
    return $value->value() + $count;
}

function literalArrayUnknownKey(int $key): mixed
{
    $array = [new LiteralArrayValue()];
    $value = $array[$key];
    return $value;
}

function literalArrayModified(): mixed
{
    $array = [new LiteralArrayValue()];
    $array[0] = 'changed';
    $value = $array[0];
    return $value;
}

function literalArrayReplace(mixed &$item): void { $item = 'replaced'; }

function literalArrayElementEscape(): mixed
{
    $array = [new LiteralArrayValue()];
    literalArrayReplace($array[0]);
    $value = $array[0];
    return $value;
}

function literalArrayCaptureEscape(): mixed
{
    $array = [new LiteralArrayValue()];
    $change = static function () use (&$array): void { $array[0] = 9; };
    $change();
    $value = $array[0];
    return $value;
}

function literalArraySnapshot(): int
{
    $array = [new LiteralArrayValue()];
    [$value] = $array;
    $array[0] = 'changed';
    return $value->value();
}

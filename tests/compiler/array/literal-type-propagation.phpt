--TEST--
Literal array reads and destructuring retain proven types without constraining mutations or references
--FILE--
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

class LiteralArrayBase { public function value(): int { return 1; } }
class LiteralArrayChild extends LiteralArrayBase { public function value(): int { return 9; } }
function literalArrayPolymorphic(LiteralArrayBase $input): int
{
    $array = [$input];
    return $array[0]->value();
}
function literalArrayReferenceLiteral(): mixed
{
    $input = json_decode('1');
    $array = [&$input];
    $input = 'changed';
    return $array[0];
}
function main(): void
{
    var_dump(literalArrayRead(), literalArrayDestructure(), literalArrayKnownKeys());
    var_dump(literalArrayUnknownKey(0)->value());
    var_dump(@literalArrayUnknownKey(3));
    var_dump(literalArrayModified(), literalArrayElementEscape(), literalArrayCaptureEscape());
    var_dump(literalArraySnapshot(), literalArrayPolymorphic(new LiteralArrayChild()));
    var_dump(literalArrayReferenceLiteral());
    $source = [new LiteralArrayValue()];
    $copy = $source;
    [$value] = $source;
    $source[0] = 'changed';
    var_dump($copy[0]->value(), $value->value());
}
?>
--EXPECT--
int(7)
int(13)
int(11)
int(7)
NULL
string(7) "changed"
string(8) "replaced"
int(9)
int(7)
int(9)
string(7) "changed"
int(7)
int(7)

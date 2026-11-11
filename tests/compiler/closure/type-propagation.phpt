--TEST--
Closure parameters, value captures and direct results preserve types and runtime boundaries
--FILE--
<?php

final class ClosureTypeValue
{
    public function __construct(public int $number = 7) {}
    public function value(): int { return $this->number; }
}

class ClosureTypeBase
{
    public function value(): int { return 1; }
}

class ClosureTypeChild extends ClosureTypeBase
{
    public function value(): int { return 9; }
}

function closureTypedLocal(ClosureTypeValue $value): int
{
    $make = static fn(ClosureTypeValue $input): ClosureTypeValue => $input;
    $item = $make($value);
    $count = static fn(int $number): int => $number + 1;
    $answer = $count(2);
    return $item->value() + $answer;
}

function closureTypedEscape(ClosureTypeValue $value, int $amount): Closure
{
    return static function (ClosureTypeValue $input) use ($value, $amount): int {
        return $input->value() + $value->value() + $amount;
    };
}

function closurePolymorphicEscape(): Closure
{
    return static fn(ClosureTypeBase $value): int => $value->value();
}

function closureNullableEscape(): Closure
{
    return static fn(?ClosureTypeValue $value): ?ClosureTypeValue => $value;
}

function closureRefCapture(mixed &$value): Closure
{
    return static function () use (&$value): mixed { return $value; };
}

function main(): void
{
    $value = new ClosureTypeValue();
    var_dump(closureTypedLocal($value));
    $callback = closureTypedEscape($value, 3);
    var_dump($callback(new ClosureTypeValue(5)));
    try {
        $callback(new stdClass());
    } catch (TypeError $error) {
        echo "parameter checked\n";
    }
    $polymorphic = closurePolymorphicEscape();
    var_dump($polymorphic(new ClosureTypeChild()));
    $nullable = closureNullableEscape();
    var_dump($nullable(null), $nullable($value) === $value);

    $source = json_decode('1');
    $read = closureRefCapture($source);
    $source = 'changed';
    var_dump($read());

    $array = [1];
    $snapshot = static function (array $input) use ($array): array {
        $input[] = 3;
        $array[] = 4;
        return [$input, $array];
    };
    $array[] = 2;
    var_dump($snapshot($array), $array);

    $badReturn = static function (mixed $ignored): int { return json_decode('"wrong"'); };
    try {
        array_map($badReturn, [1]);
    } catch (TypeError $error) {
        echo "return checked\n";
    }
}
?>
--EXPECT--
int(10)
int(15)
parameter checked
int(9)
NULL
bool(true)
string(7) "changed"
array(2) {
  [0]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    int(2)
    [2]=>
    int(3)
  }
  [1]=>
  array(2) {
    [0]=>
    int(1)
    [1]=>
    int(4)
  }
}
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
return checked

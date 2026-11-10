--TEST--
Immutable bindings preserve read-only closure and generator captures while array copies stay mutable
--FILE--
<?php
function immutable_local_read(#[Immutable] array $values): int
{
    $read = fn(): int => $values[0];
    return $read();
}

class ImmutableCaptureReader
{
    public int $value = 7;

    #[Immutable]
    public function read(): int
    {
        return $this->value;
    }

    #[Immutable]
    public function captures(#[Immutable] array $values): array
    {
        $arrow = fn(): int => $this->read() + $values[0];
        $ordinary = function () use ($values): int {
            return $this->read() + $values[1];
        };
        $generator = function () use ($values): Generator {
            yield $this->read() + $values[0];
        };
        return [$arrow(), $ordinary(), $generator()->current()];
    }
}

function immutable_generator_array_copy(#[Immutable] array $values): Closure
{
    return function () use ($values): Generator {
        $copy = $values;
        $copy[] = 99;
        yield $copy;
    };
}

function main(): void
{
    var_dump(immutable_local_read([2, 3]));
    var_dump((new ImmutableCaptureReader())->captures([2, 3]));
    $factory = immutable_generator_array_copy([2, 3]);
    var_dump($factory()->current());
}
?>
--EXPECT--
int(2)
array(3) {
  [0]=>
  int(9)
  [1]=>
  int(10)
  [2]=>
  int(9)
}
array(3) {
  [0]=>
  int(2)
  [1]=>
  int(3)
  [2]=>
  int(99)
}

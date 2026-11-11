--TEST--
Closure and literal-array type propagation retain varint overflow and return checks
--FILE--
<?php
use varint_types;

function checkedClosureIncrement(int $value): int
{
    $increment = static fn(int $input): int => $input + 1;
    return $increment($value);
}
function dynamicClosureIncrement(): Closure
{
    return static fn(int $input): mixed => $input + 1;
}
function literalArrayIncrement(int $value): mixed
{
    $array = [$value + 1];
    [$result] = $array;
    return $result;
}
function main(): void
{
    var_dump(checkedClosureIncrement(1));
    try {
        checkedClosureIncrement(PHP_INT_MAX);
    } catch (TypeError $error) {
        echo "overflow return checked\n";
    }
    $increment = dynamicClosureIncrement();
    var_dump(is_float($increment(PHP_INT_MAX)));
    var_dump(is_float(literalArrayIncrement(PHP_INT_MAX)));
}
?>
--EXPECT--
int(2)
overflow return checked
bool(true)
bool(true)

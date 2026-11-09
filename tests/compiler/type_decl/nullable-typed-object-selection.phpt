--TEST--
Nullable typed object selection preserves null across ternary cleanup and match returns
--FILE--
<?php
class NullableSelectionValue {}
class NullableSelectionState
{
    public bool $first = true;
    public ?NullableSelectionValue $value = null;
}
function nullableSelectionValue(?NullableSelectionValue $value): ?NullableSelectionValue
{
    return $value;
}
function nullableSelectionTernary(NullableSelectionState $state, ?NullableSelectionValue $value): ?NullableSelectionValue
{
    $values = [$value];
    return $state->first ? nullableSelectionValue($values[0]) : nullableSelectionValue($values[0]);
}
function nullableSelectionMatch(int $arm, ?NullableSelectionValue $value): ?NullableSelectionValue
{
    return match ($arm) {
        0 => nullableSelectionValue($value),
        default => nullableSelectionValue($value),
    };
}
function nullableSelectionProperty(NullableSelectionState $state): ?NullableSelectionValue
{
    return match (0) { default => $state->value };
}
function nullableSelectionPropertyTernary(NullableSelectionState $state): ?NullableSelectionValue
{
    return $state->first ? $state->value : $state->value;
}
function main(): void
{
    $state = new NullableSelectionState();
    var_dump(nullableSelectionTernary($state, null));
    $state->first = false;
    var_dump(nullableSelectionTernary($state, null));
    var_dump(nullableSelectionMatch(0, null), nullableSelectionMatch(1, null));
    var_dump(nullableSelectionProperty($state), nullableSelectionPropertyTernary($state));
    $value = new NullableSelectionValue();
    $state->value = $value;
    var_dump(nullableSelectionTernary($state, $value) === $value);
    $state->first = true;
    var_dump(nullableSelectionTernary($state, $value) === $value);
    var_dump(nullableSelectionMatch(0, $value) === $value, nullableSelectionMatch(1, $value) === $value);
    var_dump(nullableSelectionProperty($state) === $value, nullableSelectionPropertyTernary($state) === $value);
}
?>
--EXPECT--
NULL
NULL
NULL
NULL
NULL
NULL
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)

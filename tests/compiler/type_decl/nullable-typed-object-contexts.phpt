--TEST--
Nullable typed objects retain variadic, reference-capture, generator and trait contracts
--FILE--
<?php
interface NullableContextContract { public function name(): string; }
class NullableContextValue implements NullableContextContract
{
    public function name(): string { return 'value'; }
}
trait NullableContextTrait
{
    public function copy(?self $value = null): ?self { return $value; }
}
class NullableContextOwner { use NullableContextTrait; }
class NullableContextParent {}
class NullableContextChild extends NullableContextParent
{
    public function parentValue(?parent $value = null): ?parent { return $value; }
    public static function make(bool $empty): ?static { return $empty ? null : new static(); }
}
function nullableContextInterface(?NullableContextContract $value): ?NullableContextContract { return $value; }
function nullableContextVariadic(?NullableContextValue ...$values): array
{
    $result = [];
    foreach ($values as $value) { $result[] = $value?->name(); }
    return $result;
}
function nullableContextCapture(?NullableContextValue $value): ?NullableContextValue
{
    $clear = function () use (&$value): void { $value = null; };
    $clear();
    return $value;
}
function nullableContextGenerator(?NullableContextValue $value): Iterator { yield $value; }
function main(): void
{
    var_dump(nullableContextInterface(null));
    echo nullableContextInterface(new NullableContextValue())->name(), "\n";
    echo json_encode(nullableContextVariadic(null, new NullableContextValue(), null)), "\n";
    $fn = 'nullableContextVariadic';
    echo json_encode($fn(null, new NullableContextValue())), "\n";
    var_dump(nullableContextCapture(new NullableContextValue()));
    foreach (nullableContextGenerator(null) as $value) { var_dump($value); }
    foreach (nullableContextGenerator(new NullableContextValue()) as $value) { echo $value->name(), "\n"; }
    $owner = new NullableContextOwner();
    var_dump($owner->copy());
    var_dump($owner->copy($owner) === $owner);
    $child = new NullableContextChild();
    var_dump($child->parentValue());
    var_dump($child->parentValue($child) === $child);
    var_dump(NullableContextChild::make(true));
    var_dump(NullableContextChild::make(false) instanceof NullableContextChild);
}
?>
--EXPECT--
NULL
value
[null,"value",null]
[null,"value"]
NULL
NULL
value
NULL
bool(true)
NULL
bool(true)
NULL
bool(true)

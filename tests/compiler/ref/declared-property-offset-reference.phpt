--TEST--
References to known property slots preserve inherited defaults, nulls, and initialization errors
--FILE--
<?php
final class OffsetReferenceValue
{
}

class OffsetReferenceBase
{
    protected array $family = ['base'];
    public ?array $optional = null;
    public OffsetReferenceValue $uninitialized;

    public function popFamily(): mixed
    {
        return array_pop($this->family);
    }

    public function family(): array
    {
        return $this->family;
    }
}

final class OffsetReferenceChild extends OffsetReferenceBase
{
    protected array $family = ['first', 'second'];
}

function initializeOptionalByReference(OffsetReferenceBase $object): void
{
    $alias =& $object->optional;
    var_dump($alias);
    $alias = ['set'];
}

function isOffsetReferenceValue(mixed &$value): bool
{
    return $value instanceof OffsetReferenceValue;
}

function hasInitializedReference(OffsetReferenceBase $object): bool
{
    return isOffsetReferenceValue($object->uninitialized);
}

function main(): void
{
    $object = new OffsetReferenceChild();
    echo 'pop: ', $object->popFamily(), "\n";
    echo 'family: ', implode(',', $object->family()), "\n";
    initializeOptionalByReference($object);
    echo 'optional: ', implode(',', $object->optional), "\n";

    $object->uninitialized = new OffsetReferenceValue();
    unset($object->uninitialized);
    try {
        hasInitializedReference($object);
    } catch (Error $error) {
        echo "uninitialized property rejected\n";
    }
    $object->uninitialized = new OffsetReferenceValue();
    var_dump(hasInitializedReference($object));
}
?>
--EXPECT--
pop: second
family: first
NULL
optional: set
uninitialized property rejected
bool(true)

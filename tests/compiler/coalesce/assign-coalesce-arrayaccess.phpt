--TEST--
ArrayAccess ??= preserves indirect targets and mutable phase dependencies
--FILE--
<?php

final class IndirectCoalesceBag implements ArrayAccess
{
    public array $calls = [];
    public ?Closure $onExists = null;

    public function __construct(public array $data = [])
    {
    }

    public function offsetExists(mixed $offset): bool
    {
        $this->calls[] = "exists:$offset";
        if ($this->onExists !== null) {
            ($this->onExists)();
        }
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->calls[] = "get:$offset";
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->calls[] = "set:$offset";
        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }
}

final class IndirectCoalesceHolder
{
    public int $gets = 0;
    public mixed $value = [];

    public function __construct(public IndirectCoalesceBag $bag)
    {
    }

    public function __get(string $name): mixed
    {
        $this->gets++;
        return $this->bag;
    }
}

function rebindCoalesceToArray(mixed &$container, string &$key): int
{
    $container = [];
    $key = 'array-key';
    return 81;
}

function rebindCoalesceToObject(
    mixed &$container,
    string &$key,
    IndirectCoalesceBag $replacement,
): int {
    $container = $replacement;
    $key = 'object-key';
    return 82;
}

function rebindCoalesceKeyToObject(mixed &$container, IndirectCoalesceBag $replacement): string
{
    $container = $replacement;
    return 'key';
}

function inspectNestedCoalesceRhs(array $container): int
{
    echo 'nested-rhs:', json_encode($container), "\n";
    return 54;
}

function showIndirectCoalesce(string $label, mixed $value): void
{
    echo $label, ':', json_encode($value), "\n";
}

function main(): void
{
    $nestedBag = new IndirectCoalesceBag();
    $nested = ['bag' => $nestedBag];
    $result = ($nested['bag']['key'] ??= 51);
    showIndirectCoalesce('nested', [$result, $nestedBag->data, $nestedBag->calls]);

    $innerBag = new IndirectCoalesceBag();
    $outerBag = new IndirectCoalesceBag(['bag' => $innerBag]);
    $result = ($outerBag['bag']['key'] ??= 55);
    showIndirectCoalesce('nested-access', [
        $result,
        $outerBag->calls,
        $innerBag->data,
        $innerBag->calls,
    ]);

    $missingNested = [];
    $result = ($missingNested['bag']['key'] ??= inspectNestedCoalesceRhs($missingNested));
    showIndirectCoalesce('nested-missing', [$result, $missingNested]);

    $magicBag = new IndirectCoalesceBag();
    $magic = new IndirectCoalesceHolder($magicBag);
    $result = ($magic->virtual['key'] ??= 52);
    showIndirectCoalesce('magic', [$result, $magic->gets, $magicBag->data, $magicBag->calls]);

    $property = new IndirectCoalesceHolder(new IndirectCoalesceBag());
    $result = ($property->value['key'] ??= 53);
    showIndirectCoalesce('property-array', [$result, $property->value]);

    $phase = new IndirectCoalesceBag(['old' => 71]);
    $phaseOriginal = $phase;
    $phaseReplacement = new IndirectCoalesceBag(['new' => 72]);
    $phaseKey = 'old';
    $phaseOriginal->onExists = function () use (&$phase, &$phaseKey, $phaseReplacement): void {
        $phase = $phaseReplacement;
        $phaseKey = 'new';
    };
    $result = ($phase[$phaseKey] ??= 73);
    showIndirectCoalesce('phase-hit', [
        $result,
        $phaseKey,
        $phaseOriginal->calls,
        $phaseReplacement->calls,
    ]);

    $miss = new IndirectCoalesceBag();
    $missOriginal = $miss;
    $missReplacement = new IndirectCoalesceBag();
    $missKey = 'old';
    $missOriginal->onExists = function () use (&$miss, &$missKey, $missReplacement): void {
        $miss = $missReplacement;
        $missKey = 'new';
    };
    $result = ($miss[$missKey] ??= 74);
    showIndirectCoalesce('phase-miss', [
        $result,
        $missKey,
        $missOriginal->data,
        $missOriginal->calls,
        $missReplacement->data,
        $missReplacement->calls,
    ]);

    $objectToArray = new IndirectCoalesceBag();
    $objectOriginal = $objectToArray;
    $key = 'old';
    $result = ($objectToArray[$key] ??= rebindCoalesceToArray($objectToArray, $key));
    showIndirectCoalesce('object-array', [$result, $key, $objectToArray, $objectOriginal->calls]);

    $arrayToObject = [];
    $objectReplacement = new IndirectCoalesceBag();
    $key = 'old';
    $result = ($arrayToObject[$key] ??= rebindCoalesceToObject($arrayToObject, $key, $objectReplacement));
    showIndirectCoalesce('array-object', [$result, $key, $objectReplacement->data, $objectReplacement->calls]);

    $keyRebound = [];
    $keyReplacement = new IndirectCoalesceBag();
    $result = ($keyRebound[rebindCoalesceKeyToObject($keyRebound, $keyReplacement)] ??= 83);
    showIndirectCoalesce('key-object', [$result, $keyReplacement->data, $keyReplacement->calls]);
}
?>
--EXPECT--
nested:[51,{"key":51},["exists:key","set:key"]]
nested-access:[55,["exists:bag","get:bag","get:bag"],{"key":55},["exists:key","set:key"]]
nested-rhs:[]
nested-missing:[54,{"bag":{"key":54}}]
magic:[52,2,{"key":52},["exists:key","set:key"]]
property-array:[53,{"key":53}]
phase-hit:[71,"new",["exists:old","get:old"],[]]
phase-miss:[74,"new",[],["exists:old"],{"new":74},["set:new"]]
object-array:[81,"array-key",{"array-key":81},["exists:old"]]
array-object:[82,"object-key",{"object-key":82},["set:object-key"]]
key-object:[83,{"key":83},["exists:key","set:key"]]

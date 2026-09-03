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

final class NullKeyCoalesceBag implements ArrayAccess
{
    public array $calls = [];

    public function offsetExists(mixed $offset): bool
    {
        $this->calls[] = ['exists', $offset];
        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->calls[] = ['get', $offset];
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->calls[] = ['set', $offset, $value];
    }

    public function offsetUnset(mixed $offset): void
    {
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

function dynamicCoalesceWrite(mixed $container, mixed $key, mixed $rhs): mixed
{
    return [$container[$key] ??= $rhs, $container];
}

function referencedCoalesceWrite(mixed &$container, mixed $key, mixed $rhs): mixed
{
    return $container[$key] ??= $rhs;
}

function showDynamicCoalesce(string $label, mixed $container, mixed $key, mixed $rhs): void
{
    set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
        echo 'warning:', $message, "\n";
        return true;
    });
    try {
        showIndirectCoalesce($label, dynamicCoalesceWrite($container, $key, $rhs));
    } catch (Throwable $error) {
        showIndirectCoalesce($label, [$error::class, $error->getMessage(), $container]);
    } finally {
        restore_error_handler();
    }
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

    showDynamicCoalesce('dynamic-null', null, 'key', 91);
    showDynamicCoalesce('dynamic-false', false, 'key', 92);
    showDynamicCoalesce('dynamic-true', true, 'key', 93);
    showDynamicCoalesce('dynamic-int', 1, 'key', 94);
    showDynamicCoalesce('dynamic-float', 1.5, 'key', 95);
    showDynamicCoalesce('dynamic-array-string', [], 'key', 96);
    showDynamicCoalesce('dynamic-array-offset', [], 3, 97);
    showDynamicCoalesce('dynamic-array-null-key', [], null, 100);
    showDynamicCoalesce('dynamic-string-hit', 'abc', 1, 'XY');
    showDynamicCoalesce('dynamic-string-write', 'abc', 5, 'XY');
    showDynamicCoalesce('dynamic-string-key', 'abc', 'key', 'Z');

    $referenced = [];
    $alias =& $referenced;
    $result = referencedCoalesceWrite($alias, 'key', 98);
    showIndirectCoalesce('dynamic-reference', [$result, $referenced, $alias]);

    $dynamicObject = new IndirectCoalesceBag();
    $result = referencedCoalesceWrite($dynamicObject, 'key', 99);
    showIndirectCoalesce('dynamic-object', [$result, $dynamicObject->data, $dynamicObject->calls]);

    $nullKeyObject = new NullKeyCoalesceBag();
    $result = referencedCoalesceWrite($nullKeyObject, null, 101);
    showIndirectCoalesce('dynamic-object-null-key', [$result, $nullKeyObject->calls]);
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
dynamic-null:[91,{"key":91}]
warning:Automatic conversion of false to array is deprecated
dynamic-false:[92,{"key":92}]
dynamic-true:["Error","Cannot use a scalar value as an array",true]
dynamic-int:["Error","Cannot use a scalar value as an array",1]
dynamic-float:["Error","Cannot use a scalar value as an array",1.5]
dynamic-array-string:[96,{"key":96}]
dynamic-array-offset:[97,{"3":97}]
warning:Using null as an array offset is deprecated, use an empty string instead
dynamic-array-null-key:[100,{"":100}]
dynamic-string-hit:["b","abc"]
warning:Only the first byte will be assigned to the string offset
dynamic-string-write:["X","abc  X"]
dynamic-string-key:["TypeError","Cannot access offset of type string on string","abc"]
dynamic-reference:[98,{"key":98},{"key":98}]
dynamic-object:[99,{"key":99},["exists:key","set:key"]]
dynamic-object-null-key:[101,[["exists",null],["set",null,101]]]

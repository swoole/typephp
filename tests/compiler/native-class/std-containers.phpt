--TEST--
Native class: std containers store typed native object pointers safely
--FILE--
<?php

#[Native]
class NativeContainerValue
{
    public int $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }
}

function main(): void
{
    $array = std::array(NativeContainerValue::class, 1);
    $vector = std::vector(NativeContainerValue::class);
    $map = std::map(Type::String, NativeContainerValue::class);
    $ordered = std::orderedMap(Type::Int, NativeContainerValue::class);

    $first = ($array[0] = new NativeContainerValue(11));
    $second = ($vector[] = new NativeContainerValue(22));
    $map['value'] = new NativeContainerValue(33);
    $ordered[4] = new NativeContainerValue(44);
    var_dump($first->value, $second->value);
    $first = null;
    $second = null;

    // Make the container slots the only roots, then allocate enough objects
    // to force the Native heap to collect.
    for ($i = 0; $i < 50000; $i++) {
        $temporary = new NativeContainerValue($i);
    }
    $temporary = null;

    var_dump($array[0]->value);
    var_dump($vector[0]->value);
    var_dump($map['value']->value);
    var_dump($ordered[4]->value);

    $total = 0;
    foreach ($array as $value) {
        $total += $value->value;
    }
    foreach ($vector as $value) {
        $total += $value->value;
    }
    foreach ($map as $value) {
        $total += $value->value;
    }
    foreach ($ordered as $value) {
        $total += $value->value;
    }
    var_dump($total);

    $array[0] = null;
    unset($vector[0]);
    unset($map['value']);
    unset($ordered[4]);
    var_dump(isset($array[0]));
}

?>
--EXPECT--
int(11)
int(22)
int(11)
int(22)
int(33)
int(44)
int(110)
bool(false)

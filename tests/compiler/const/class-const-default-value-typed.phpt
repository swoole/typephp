--TEST--
Typed parameter default value from an unresolvable (external) class constant
--FILE--
<?php

class TypedDefault
{
    public function run(
        int $value = \ArrayObject::ARRAY_AS_PROPS,
        float $floatValue = \ArrayObject::ARRAY_AS_PROPS,
        string $format = \DateTime::ATOM,
        int $composite = 1 | \ArrayObject::ARRAY_AS_PROPS,
        mixed $variant = \ArrayObject::STD_PROP_LIST,
        int $sum = \ArrayObject::ARRAY_AS_PROPS + 1,
        float $quotient = \ArrayObject::ARRAY_AS_PROPS / 2,
        bool $enabled = \ArrayObject::ARRAY_AS_PROPS > 0,
    )
    {
        var_dump($value, $floatValue, $format, $composite, $variant);
        var_dump($sum, $quotient, $enabled);
    }
}

function main()
{
    (new TypedDefault)->run();
}
?>
--EXPECT--
int(2)
float(2)
string(13) "Y-m-d\TH:i:sP"
int(3)
int(1)
int(3)
float(1)
bool(true)

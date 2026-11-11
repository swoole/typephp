--TEST--
Native scalar constant slots preserve varint overflow, bitwise operations and strict return errors
--FILE--
<?php
use varint_types;

const CONSTANT_TYPE_MAX = PHP_INT_MAX;
const CONSTANT_TYPE_MASK = 1023;

final class ConstantTypeOverflow
{
    public const MAX = PHP_INT_MAX;
    public const MASK = 1023;

    public function add(int $value): mixed { return self::MAX + $value; }
    public function exact(int $value): int { return self::MAX + $value; }
    public function pack(int $value): int { return $value & self::MASK; }
}

function globalAdd(int $value): mixed { return CONSTANT_TYPE_MAX + $value; }

function main(): void
{
    $value = new ConstantTypeOverflow();
    var_dump(globalAdd(0) === PHP_INT_MAX);
    var_dump(is_float(globalAdd(1)));
    var_dump(is_float($value->add(1)));
    var_dump($value->pack(1025));
    try {
        $value->exact(1);
        echo "unexpected\n";
    } catch (TypeError $error) {
        echo "return-type-error\n";
    }
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
int(1)
return-type-error

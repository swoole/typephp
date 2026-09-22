--TEST--
Unary plus property and parameter defaults use the converted numeric type
--FILE--
<?php
class PositiveDefaults {
    public int $text = +'12';
    public int $truth = +true;
    public int $nothing = +null;
    public float $decimal = +'1.5';
    public int $minimum = +'-9223372036854775808';
    public ?bool $flag = null;
    public ?int $optionalInt = null;
    public ?float $optionalFloat = null;
    public static ?float $optionalStatic = null;
    public const VALUE = +'24';
}
function defaultNumber(int $value = +'6'): int { return $value; }
function main() {
    $value = new PositiveDefaults();
    var_dump($value->text, $value->truth, $value->nothing, $value->decimal);
    var_dump(PositiveDefaults::VALUE, defaultNumber());
    var_dump($value->minimum === PHP_INT_MIN);
    var_dump(+$value->flag);
    $value->flag = true;
    var_dump(+$value->flag);
    var_dump(+$value->optionalInt, +$value->optionalFloat, +PositiveDefaults::$optionalStatic);
    var_dump(+(@$value->optionalFloat));
    var_dump(+($value->flag ? $value->optionalFloat : PositiveDefaults::$optionalStatic));
    $value->optionalFloat = 1.5;
    var_dump(+$value->optionalFloat);
}
?>
--EXPECT--
int(12)
int(1)
int(0)
float(1.5)
int(24)
int(6)
bool(true)
int(0)
int(1)
int(0)
int(0)
int(0)
int(0)
int(0)
float(1.5)

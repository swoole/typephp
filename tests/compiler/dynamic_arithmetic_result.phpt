--TEST--
Dynamic arithmetic values keep PHP numeric semantics in nested array returns
--FILE--
<?php
function dynamic_arithmetic(array $values, int $n): array
{
    return [
        'forward' => [
            'add' => $values['float'] + $n,
            'subtract' => $values['float'] - $n,
            'multiply' => $values['float'] * $n,
            'divide' => $values['float'] / $n,
            'integer_divide' => $values['integer'] / $n,
        ],
        'reverse' => [
            'add' => $n + $values['float'],
            'subtract' => $n - $values['float'],
            'multiply' => $n * $values['float'],
            'divide' => $n / $values['float'],
        ],
    ];
}

function dynamic_return(mixed $value, int $n): mixed
{
    return $value / $n;
}

function dynamic_nested(array $values, int $n): mixed
{
    return ($values['float'] + $n) * $n;
}

function dynamic_kinds(mixed $value, int $n, float $fraction): array
{
    return [$value / $n, $value + $fraction, $fraction - $value,
        $value ** $n, $value % $n, $value << $n, $value >> $n,
        $value & $n, $value | $n, $value ^ $n];
}

function main()
{
    var_dump(dynamic_arithmetic(['float' => 5.5, 'integer' => 5], 2));
    var_dump(dynamic_return(5.5, 2), dynamic_nested(['float' => 5.5], 2));
    var_dump(dynamic_kinds(4, 2, 0.5));
}
?>
--EXPECT--
array(2) {
  ["forward"]=>
  array(5) {
    ["add"]=>
    float(7.5)
    ["subtract"]=>
    float(3.5)
    ["multiply"]=>
    float(11)
    ["divide"]=>
    float(2.75)
    ["integer_divide"]=>
    float(2.5)
  }
  ["reverse"]=>
  array(4) {
    ["add"]=>
    float(7.5)
    ["subtract"]=>
    float(-3.5)
    ["multiply"]=>
    float(11)
    ["divide"]=>
    float(0.36363636363636365)
  }
}
float(2.75)
float(15)
array(10) {
  [0]=>
  int(2)
  [1]=>
  float(4.5)
  [2]=>
  float(-3.5)
  [3]=>
  int(16)
  [4]=>
  int(0)
  [5]=>
  int(16)
  [6]=>
  int(1)
  [7]=>
  int(0)
  [8]=>
  int(6)
  [9]=>
  int(6)
}

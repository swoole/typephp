--TEST--
Unary plus preserves PHP numeric conversion, errors and evaluation count
--FILE--
<?php
function positive(mixed $value): int|float { return +$value; }
function numericText(string $value): int|float { return +$value; }
function truth(bool $value): int { return +$value; }
function once(int &$calls): string { ++$calls; return '2.5'; }
function main() {
    $integer = '42';
    $decimal = '1.5';
    $boolean = true;
    var_dump(+$integer, +$decimal, +$boolean);
    $assigned = +$decimal;
    var_dump($assigned, numericText('24'), truth(false));
    foreach (['12', '1.25', '1e2', '9223372036854775808', true, false, null, 7, -0.0] as $value) {
        var_dump(positive($value));
    }
    foreach (['invalid', [], new stdClass()] as $value) {
        try { positive($value); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
    }
    set_error_handler(function ($level, $message, $file, $line) { echo $message, "\n"; return true; });
    var_dump(numericText('12tail'));
    restore_error_handler();
    $calls = 0;
    var_dump(+once($calls), $calls);
    $source = '8';
    $alias =& $source;
    $result = +$alias;
    var_dump($result, $source, $alias);
}
?>
--EXPECT--
int(42)
float(1.5)
int(1)
float(1.5)
int(24)
int(0)
int(12)
float(1.25)
float(100)
float(9.223372036854776E+18)
int(1)
int(0)
int(0)
int(7)
float(-0)
Unsupported operand types: string * int
Unsupported operand types: array * int
Unsupported operand types: stdClass * int
A non-numeric value encountered
int(12)
float(2.5)
int(1)
int(8)
string(1) "8"
string(1) "8"

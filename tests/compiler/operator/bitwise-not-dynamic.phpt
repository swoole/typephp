--TEST--
Dynamic bitwise not uses Zend for strings, integers and invalid operands
--FILE--
<?php
function invertMixed(mixed $value): mixed { return ~$value; }
function main(): void {
    $s = std::any('abc');
    $result = ~$s;
    var_dump(is_string($result), bin2hex($result));
    var_dump(bin2hex(invertMixed('1e3')));
    var_dump(invertMixed(''));
    var_dump(invertMixed(7), invertMixed(PHP_INT_MIN), invertMixed(1.0));
    $nested = std::any('xyz');
    var_dump(~~$nested);
    foreach ([true, false, [], new stdClass()] as $invalid) {
        try {
            invertMixed($invalid);
        } catch (TypeError $e) {
            echo "TypeError\n";
        }
    }
    try {
        $invalidArray = ~std::any([]);
    } catch (TypeError $e) {
        echo "array TypeError\n";
    }
}
?>
--EXPECT--
bool(true)
string(6) "9e9d9c"
string(6) "ce9acc"
string(0) ""
int(-8)
int(9223372036854775807)
int(-2)
string(3) "xyz"
TypeError
TypeError
TypeError
TypeError
array TypeError

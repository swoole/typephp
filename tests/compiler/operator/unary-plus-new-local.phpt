--TEST--
Unary plus preserves arbitrary precision values assigned to fresh locals
--FILE--
<?php
function main() {
    echo (+($integer = std::bigInt(2)))->toString(), "\n";
    echo (+($floating = std::bigFloat(2.5)))->toString(), "\n";
    echo (+($decimal = std::decimal('2.5')))->toString(), "\n";
    var_dump(+($native = 7), +($fraction = 1.5));
}
?>
--EXPECT--
2
2.5
2.5
int(7)
float(1.5)

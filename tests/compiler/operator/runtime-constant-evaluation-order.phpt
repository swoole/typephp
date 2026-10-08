--TEST--
Runtime constant lookups preserve exception ordering in calls, concat, arithmetic and comparisons
--FILE--
<?php
class OperandOrderConstant { public const NUMBER = 7; }
function constant_order_trace(): string { echo "must-not-run\n"; return 'value'; }
function constant_order_pair(mixed $a, mixed $b): void { echo "must-not-run\n"; }
function main(): void
{
    try { constant_order_pair(OPERAND_ORDER_MISSING, constant_order_trace()); }
    catch (Error $e) { echo "call\n"; }
    try { $concat = OPERAND_ORDER_MISSING . constant_order_trace(); }
    catch (Error $e) { echo "concat\n"; }
    try { $sum = OPERAND_ORDER_MISSING + constant_order_trace(); }
    catch (Error $e) { echo "arithmetic\n"; }
    try { $equal = OPERAND_ORDER_MISSING == constant_order_trace(); }
    catch (Error $e) { echo "comparison\n"; }
    $class = stdClass::class;
    try { $classConcat = $class::MISSING . constant_order_trace(); }
    catch (Error $e) { echo "class-concat\n"; }
    try { constant_order_pair($class::MISSING, constant_order_trace()); }
    catch (Error $e) { echo "class-call\n"; }
    var_dump(false && OPERAND_ORDER_MISSING, true || $class::MISSING);
    var_dump(OperandOrderConstant::NUMBER + 3, COUNT_NORMAL + 1);
}
?>
--EXPECT--
call
concat
arithmetic
comparison
class-concat
class-call
bool(false)
bool(true)
int(10)
int(1)

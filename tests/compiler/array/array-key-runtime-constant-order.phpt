--TEST--
Runtime class and global constant array keys are evaluated before their values
--FILE--
<?php
class ConstantOrderFirst { public const KEY = 'first'; public const NEXT = 'next'; }
class ConstantOrderSecond { public const KEY = 'second'; }
class ConstantOrderBase {
    public const KEY = 'base';
    public static function values(): array { return [static::KEY => key_order_value('late-static')]; }
}
class ConstantOrderChild extends ConstantOrderBase { public const KEY = 'child'; }
function key_order_value(string $label): string { echo "[$label]"; return 'value'; }
function key_order_change_class(string &$class): string {
    $class = ConstantOrderSecond::class;
    return key_order_value('change-class');
}
function key_order_define(): string {
    define('KEY_ORDER_UNDEFINED', 'too-late');
    return key_order_value('must-not-run');
}
function main(): void
{
    $class = ConstantOrderFirst::class;
    $a = [$class::KEY => key_order_change_class($class)];
    echo json_encode($a), "\n";
    $name = 'KEY';
    $a = [ConstantOrderFirst::{$name} => ($name = 'NEXT')];
    echo json_encode($a), "\n";
    echo json_encode(ConstantOrderChild::values()), "\n";
    define('KEY_ORDER_DEFINED', 'defined');
    $a = [KEY_ORDER_DEFINED => key_order_value('global')];
    echo json_encode($a), "\n";
    $class = stdClass::class;
    try { $a = [$class::MISSING => key_order_value('must-not-run')]; }
    catch (Error $e) { echo "missing-constant\n"; }
    $class = 'KeyOrderMissingClass';
    try { $a = [$class::KEY => key_order_value('must-not-run')]; }
    catch (Error $e) { echo "missing-class\n"; }
    $name = 'MISSING';
    try { $a = [ConstantOrderFirst::{$name} => key_order_value('must-not-run')]; }
    catch (Error $e) { echo "missing-dynamic-name\n"; }
    try { $a = [(string) $class::KEY => key_order_value('must-not-run')]; }
    catch (Error $e) { echo "wrapped-missing-class\n"; }
    try { $a = [KEY_ORDER_UNDEFINED => key_order_define()]; }
    catch (Error $e) { echo "missing-global\n"; }
    var_dump(defined('KEY_ORDER_UNDEFINED'));
}
?>
--EXPECT--
[change-class]{"first":"value"}
{"first":"NEXT"}
[late-static]{"child":"value"}
[global]{"defined":"value"}
missing-constant
missing-class
missing-dynamic-name
wrapped-missing-class
missing-global
bool(false)

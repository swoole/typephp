--TEST--
Captured runtime constant operands stay inside default helpers and execute only for omitted arguments
--FILE--
<?php
class DefaultOperandConstant { public const LABEL = 'label'; }
class DefaultOperandObject {
    public int $number;
    public function __construct(int $number) { echo 'constructor:', $number, "\n"; $this->number = $number; }
}
function default_operand_values(
    int $number = DEFAULT_OPERAND_NUMBER + 2,
    string $label = DefaultOperandAlias::LABEL . '!',
): void { var_dump($number, $label); }
function default_operand_missing(int $number = DEFAULT_OPERAND_MISSING + 1): void { var_dump($number); }
function default_operand_object(DefaultOperandObject $object = new DefaultOperandObject(DEFAULT_OPERAND_NUMBER)): int {
    return $object->number;
}
function main(): void
{
    default_operand_values(9, 'explicit');
    default_operand_missing(123);
    define('DEFAULT_OPERAND_NUMBER', 5);
    class_alias(DefaultOperandConstant::class, 'DefaultOperandAlias');
    default_operand_values();
    var_dump(default_operand_object());
    try { default_operand_missing(); }
    catch (Error $e) { echo "missing-default\n"; }
}
?>
--EXPECT--
int(9)
string(8) "explicit"
int(123)
int(7)
string(6) "label!"
constructor:5
int(5)
missing-default

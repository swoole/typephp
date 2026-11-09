--TEST--
Nullable typed object calls use PHPX protection and preserve nullsafe arguments and polymorphism
--FILE--
<?php
class NullableCallBase
{
    public function value(int $number = 42): int { return $number; }
    private function hidden(): string { return 'base'; }
}
class NullableCallChild extends NullableCallBase
{
    public function value(int $number = 42): int { return $number + 1; }
    public function hidden(): string { return 'child'; }
}
final class NullableCallMagic
{
    private function hidden(): string { return 'private'; }
    public function __call(string $name, array $args): string { return 'magic'; }
}
final class NullableNativeCallTarget
{
    public int $number = 7;
    public function read(): int { return $this->number; }
    public function write(): void { $this->number = 8; }
}
function nullableCallEffect(): int { echo "argument evaluated\n"; return 5; }
function nullableCallNormal(?NullableCallBase $object): void
{
    try { var_dump($object->value()); }
    catch (Error $e) { echo "null receiver rejected\n"; }
}
function nullableCallSafe(?NullableCallBase $object): void { var_dump($object?->value(nullableCallEffect())); }
function nullableCallPrivate(?NullableCallBase $object): string { return $object->hidden(); }
function nullableCallMagic(?NullableCallMagic $object): string { return $object->hidden(); }
function nullableNativeCall(?NullableNativeCallTarget $object): void
{
    try { var_dump($object->read()); } catch (Error $e) { echo "native read rejected\n"; }
    try { $object->write(); } catch (Error $e) { echo "native write rejected\n"; }
    $method = 'read';
    try { var_dump($object->$method()); } catch (Error $e) { echo "dynamic call rejected\n"; }
}
function main(): void
{
    nullableCallNormal(null);
    nullableCallSafe(null);
    nullableCallNormal(new NullableCallChild());
    nullableCallSafe(new NullableCallChild());
    echo nullableCallPrivate(new NullableCallChild()), "\n";
    echo nullableCallMagic(new NullableCallMagic()), "\n";
    nullableNativeCall(null);
}
?>
--EXPECT--
null receiver rejected
NULL
int(43)
argument evaluated
int(6)
child
magic
native read rejected
native write rejected
dynamic call rejected

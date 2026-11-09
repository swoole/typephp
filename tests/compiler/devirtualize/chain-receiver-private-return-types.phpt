--TEST--
Chained dynamic dispatch does not infer returns from an inaccessible declaration
--FILE--
<?php
class ChainDeclaredResult
{
    public function label(): string { return 'declared'; }
}
class ChainActualResult
{
    public function label(): string { return 'actual'; }
}
class ChainReturnBase
{
    public static function make(): self { return new ChainReturnChild(); }
    private function value(): int { return 1; }
    private function result(): ChainDeclaredResult { return new ChainDeclaredResult(); }
    public function lexicalValue(): int { return $this->value(); }
}
class ChainReturnChild extends ChainReturnBase
{
    public function value(): string { return 'child'; }
    public function result(): ChainActualResult { return new ChainActualResult(); }
}
class ChainReturnMagic
{
    public static function make(): self { return new self(); }
    private function value(): int { return 1; }
    public function __call(string $name, array $args): mixed { return 'magic'; }
}
function main(): void
{
    $value = ChainReturnBase::make()->value();
    var_dump($value);
    $object = ChainReturnBase::make();
    $value = $object->value();
    var_dump($value);
    echo ChainReturnBase::make()->result()->label(), "\n";
    $magic = ChainReturnMagic::make()->value();
    var_dump($magic);
    var_dump(ChainReturnBase::make()->lexicalValue());
}
?>
--EXPECT--
string(5) "child"
string(5) "child"
actual
string(5) "magic"
int(1)

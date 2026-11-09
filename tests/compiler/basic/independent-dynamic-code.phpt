--TEST--
Independent eval/include/require code returns closures called with explicit arguments
--FILE--
<?php
class IndependentDynamicCode
{
    public int $value = 0;

    public static function make(): self { return new self(); }

    public function execute(int $input): self
    {
        $operation = eval('return static function (int $value): int { return $value + 1; };');
        $this->value = $operation($input);
        $operation = include __DIR__ . '/independent-dynamic-code.inc';
        $this->value = $operation($this->value);
        $operation = require __DIR__ . '/independent-dynamic-code.inc';
        $this->value = $operation($this->value);
        return $this;
    }

    public function result(): int { return $this->value; }
}
function main(): void
{
    echo IndependentDynamicCode::make()->execute(37)->result(), "\n";
    $operation = eval('return static function (int $value): int { return $value * 2; };');
    echo $operation(21), "\n";
    var_dump(include_once __DIR__ . '/independent-dynamic-code.inc');
    var_dump(require_once __DIR__ . '/independent-dynamic-code.inc');
}
?>
--EXPECT--
42
42
bool(true)
bool(true)

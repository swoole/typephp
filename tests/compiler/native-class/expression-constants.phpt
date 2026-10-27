--TEST--
Native class expression constants keep values and declaring scope without Zend lookups
--FILE--
<?php
namespace NativeExpressions {

#[\Native]
class BaseFlags
{
    public const A = 1;
    public const B = 2;
    public const MASK = self::A | self::B;

    public function mask(): int
    {
        return self::MASK;
    }
}

#[\Native]
class ChildFlags extends BaseFlags
{
    public const A = 8;
    public const TOTAL = parent::MASK + 4;
    public const LABEL = 'tmp_var_' . self::TOTAL;
    public const ENABLED = self::TOTAL > 3;
    public const NOTHING = self::ENABLED ? null : 1;
    public const RATIO = self::TOTAL / 2;
    public const ITEMS = [self::MASK, self::TOTAL];

    public function inheritedMask(): int
    {
        return self::MASK;
    }

    public function values(): array
    {
        return [self::TOTAL, self::LABEL, self::ENABLED, self::NOTHING, self::RATIO, self::ITEMS];
    }
}
}

namespace {
function main(): void
{
    $flags = new \NativeExpressions\ChildFlags();
    var_dump($flags->mask(), $flags->inheritedMask(), $flags->values());
}
}
?>
--EXPECT--
int(3)
int(3)
array(6) {
  [0]=>
  int(7)
  [1]=>
  string(9) "tmp_var_7"
  [2]=>
  bool(true)
  [3]=>
  NULL
  [4]=>
  float(3.5)
  [5]=>
  array(2) {
    [0]=>
    int(3)
    [1]=>
    int(7)
  }
}

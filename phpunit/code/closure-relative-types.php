<?php
namespace ClosureRelativeTypes {
    class Base {}
    class Item extends Base
    {
        public function __construct(public int $id) {}
        public static function compare(): \Closure
        {
            return static function (self $left, self $right): int {
                return $left->id <=> $right->id;
            };
        }
        public static function parentEcho(): \Closure
        {
            return static fn(parent $value): parent => $value;
        }
        public static function nullableEcho(): \Closure
        {
            return static fn(?self $value): ?self => $value;
        }
        public static function local(self $value): self
        {
            $identity = static fn(self $item): self => $item;
            return $identity($value);
        }
    }
}
namespace {
    function main(): void
    {
        $first = new \ClosureRelativeTypes\Item(1);
        $second = new \ClosureRelativeTypes\Item(2);
        $values = [$second, $first];
        $compare = \ClosureRelativeTypes\Item::compare();
        usort($values, $compare);
        var_dump($values[0]->id, $values[1]->id);
        $parent = \ClosureRelativeTypes\Item::parentEcho();
        var_dump($parent(new \ClosureRelativeTypes\Base()) instanceof \ClosureRelativeTypes\Base);
        $nullable = \ClosureRelativeTypes\Item::nullableEcho();
        var_dump($nullable(null), $nullable($first) === $first);
        var_dump(\ClosureRelativeTypes\Item::local($first) === $first);
        try { $compare(new \stdClass(), $first); }
        catch (\TypeError $error) { echo "self checked\n"; }
    }
}

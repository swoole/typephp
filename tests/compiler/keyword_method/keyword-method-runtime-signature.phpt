--TEST--
Dynamic keyword dispatch rejects a runtime class with a mismatching signature
--FILE--
<?php

function main(): void
{
    eval('class RtOk { public function toInt(): int { return 7; } }
          class RtBadReturn { public function toInt(): string { return "7"; } }
          class RtNullable { public function toInt(): ?int { return 7; } }
          class RtParam { public function toInt(int $x = 1): int { return 7; } }
          class RtStatic { public static function toInt(): int { return 7; } }
          class RtNoType { public function toInt() { return 7; } }');
    foreach (['RtOk', 'RtBadReturn', 'RtNullable', 'RtParam', 'RtStatic', 'RtNoType'] as $name) {
        $value = std::any(new $name());
        try {
            echo $value->toInt(), "\n";
        } catch (Error $e) {
            echo $e->getMessage(), "\n";
        }
    }
}
?>
--EXPECT--
7
RtBadReturn::toInt() must be a public, non-static method without parameters returning exactly int
RtNullable::toInt() must be a public, non-static method without parameters returning exactly int
RtParam::toInt() must be a public, non-static method without parameters returning exactly int
RtStatic::toInt() must be a public, non-static method without parameters returning exactly int
RtNoType::toInt() must be a public, non-static method without parameters returning exactly int

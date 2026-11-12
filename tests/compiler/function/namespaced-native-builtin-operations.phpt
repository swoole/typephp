--TEST--
Namespaced Native keyword and std container operations retain their static ABI
--FILE--
<?php
namespace NativeBuiltinOperations {
    use std;
    use Type;

    #[\Native]
    final class Value implements \Countable {
        public function count(): int { return 3; }
    }

    function size(Value $value): int { return count($value); }
    function missing(?Value $value): bool { return is_null($value); }
    function vectorSize(): int {
        $vector = std::vector(Type::Int, 2);
        $vector[] = 3;
        return count($vector);
    }
}
namespace {
    function main(): void {
        echo \NativeBuiltinOperations\size(new \NativeBuiltinOperations\Value()), "\n";
        var_dump(\NativeBuiltinOperations\missing(null));
        var_dump(\NativeBuiltinOperations\missing(new \NativeBuiltinOperations\Value()));
        echo \NativeBuiltinOperations\vectorSize(), "\n";
    }
}
?>
--EXPECT--
3
bool(true)
bool(false)
3

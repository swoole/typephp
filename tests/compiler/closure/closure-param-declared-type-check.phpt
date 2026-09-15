--TEST--
Escaping Closure enforces declared parameter types
--FILE--
<?php
declare(strict_types=1);

function sink($c): void
{
}

function main(): void
{
    // These Closures escape, so they are built through the Zend Closure path.
    // phpx's ClosureParameter carries no type information, so the declared
    // PHP signature has to be enforced from inside the ClosureFn. Without it
    // a typed parameter silently accepted any argument.
    $int = fn(int $r) => $r + 1;
    sink($int);
    try {
        var_dump($int(3.14));
        echo "int: no error\n";
    } catch (TypeError $e) {
        echo "int: TypeError\n";
    }

    $str = fn(string $v): int => strlen($v);
    sink($str);
    try {
        var_dump($str(42));
        echo "string: no error\n";
    } catch (TypeError $e) {
        echo "string: TypeError\n";
    }

    $arr = fn(array $v): int => count($v);
    sink($arr);
    try {
        var_dump($arr(42));
        echo "array: no error\n";
    } catch (TypeError $e) {
        echo "array: TypeError\n";
    }

    $bool = fn(bool $p) => !$p;
    sink($bool);
    var_dump($bool(true));

    $int = fn(int $r) => $r + 1;
    sink($int);
    var_dump($int(41));
}
?>
--EXPECT--
int: TypeError
string: TypeError
array: TypeError
bool(false)
int(42)

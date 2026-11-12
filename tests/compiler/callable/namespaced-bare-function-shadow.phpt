--TEST--
Bare callable conversion on a global fallback does not change namespace shadow constant arguments
--FILE--
<?php
namespace BareFunctionFallback {
    function local_callback(int $value): int { return $value * 2; }
    function positional(): mixed { return array_map(local_callback, [1, 2]); }
    function named(): mixed { return array_map(callback: local_callback, array: [3]); }
    function ordinary(): mixed { return local_callback; }
}
namespace BareFunctionShadow {
    function local_callback(int $value): int { return $value * 2; }
    function positional(): mixed { return array_map(local_callback, [1]); }
    function named(): mixed { return array_map(callback: local_callback, array: [1]); }
}
namespace {
    function main(): void {
        echo implode(',', \BareFunctionFallback\positional()), "\n";
        echo implode(',', \BareFunctionFallback\named()), "\n";
        try {
            \BareFunctionFallback\ordinary();
        } catch (\Error $error) {
            echo "ordinary constant error\n";
        }
        eval(<<<'PHP'
namespace BareFunctionShadow;
function array_map($callback, $array): mixed { return $callback; }
PHP);
        try {
            \BareFunctionShadow\positional();
        } catch (\Error $error) {
            echo "shadow constant error\n";
        }
        eval('namespace BareFunctionShadow; const local_callback = "literal";');
        var_dump(\BareFunctionShadow\positional());
        var_dump(\BareFunctionShadow\named());
    }
}
?>
--EXPECT--
2,4
6
ordinary constant error
shadow constant error
string(7) "literal"
string(7) "literal"

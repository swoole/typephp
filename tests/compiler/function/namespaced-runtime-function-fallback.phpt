--TEST--
Namespaced runtime functions override global builtins and compiled globals with independent signatures
--FILE--
<?php
namespace {
    function globalFallback(int $value): int {
        return $value + 1;
    }
}
namespace RuntimeFunctionFallback {
    use function strlen as globalLength;

    function length(string $value): mixed { return strlen($value); }
    function differentSignature(): mixed { return strlen(value: 'a', suffix: 'b'); }
    function unpacked(array $args): mixed { return strlen(...$args); }
    function firstClass(): \Closure { return strlen(...); }
    function globalNativeFallback(int $value): mixed { return globalFallback($value); }
    function explicitGlobal(string $value): int { return \strlen($value) + globalLength($value); }
    function mutate(int &$value): void { sort(std::ref($value)); }
    function cachedCount(): mixed { return count([1, 2, 3]); }
    function throwing(): mixed { return trim('x'); }
    function append(array &$values): int { return array_push($values, 5); }
    function valueShadow(): mixed { return array_pop('input'); }
    function shortCircuit(bool $run): bool { return $run && trim('x'); }
    function argument(): string {
        eval('namespace RuntimeFunctionFallback; function strrev(string $value): string { return "shadow"; }');
        return 'abc';
    }
    function resolutionBeforeArgument(): mixed { return strrev(argument()); }
    function outputReference(): string {
        preg_match('/a/', 'abc', $matches);
        return $matches[0];
    }
}
namespace {
    function main(): void {
        var_dump(\RuntimeFunctionFallback\cachedCount());
        eval(<<<'PHP'
namespace RuntimeFunctionFallback;
function strlen(string $value, string $suffix = ''): string { return $value . $suffix . '!'; }
function globalFallback(int $value): string { return 'shadow:' . $value; }
function sort(int &$value): void { $value += 10; }
function count(array $value): int { return 99; }
function trim(string $value): never { throw new \RuntimeException('shadow exception'); }
function array_pop(string $value): string { return $value . '!'; }
PHP);
        var_dump(\RuntimeFunctionFallback\length('abc'));
        var_dump(\RuntimeFunctionFallback\differentSignature());
        var_dump(\RuntimeFunctionFallback\unpacked(['value' => 'c', 'suffix' => 'd']));
        $callable = \RuntimeFunctionFallback\firstClass();
        var_dump($callable('e'));
        var_dump(\RuntimeFunctionFallback\globalNativeFallback(5));
        var_dump(\RuntimeFunctionFallback\explicitGlobal('abc'));
        $value = 2;
        \RuntimeFunctionFallback\mutate($value);
        var_dump($value);
        var_dump(\RuntimeFunctionFallback\cachedCount());
        try {
            \RuntimeFunctionFallback\throwing();
        } catch (\RuntimeException $error) {
            echo $error->getMessage(), "\n";
        }
        $values = [1];
        var_dump(\RuntimeFunctionFallback\append($values));
        echo implode(',', $values), "\n";
        var_dump(\RuntimeFunctionFallback\valueShadow());
        var_dump(\RuntimeFunctionFallback\shortCircuit(false));
        var_dump(\RuntimeFunctionFallback\resolutionBeforeArgument());
        var_dump(\RuntimeFunctionFallback\outputReference());
    }
}
?>
--EXPECT--
int(3)
string(4) "abc!"
string(3) "ab!"
string(3) "cd!"
string(2) "e!"
string(8) "shadow:5"
int(6)
int(12)
int(3)
shadow exception
int(2)
1,5
string(6) "input!"
bool(false)
string(3) "cba"
string(1) "a"

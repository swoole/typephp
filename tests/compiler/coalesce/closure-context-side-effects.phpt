--TEST--
Repeated closure lowering preserves arrow and nested selections and evaluates side effects once
--FILE--
<?php
function arrowSelection(string $text): string
{
    return preg_replace_callback('/(X)|(q)(.+?)\2/', fn($m) => ($m[1] ?? '') !== '' ? '[' . $m[1] . ']' : '<' . $m[2] . $m[3] . '>', $text) ?? $text;
}

function shorthandSelection(string $text): string
{
    return preg_replace_callback('/(X)|(q)(.+?)\2/', function ($m) {
        return '[' . ($m[1] ?: ($m[2] . $m[3])) . ']';
    }, $text) ?? $text;
}

function nestedSelection(string $text): string
{
    return preg_replace_callback('/(X)|(q)(.+?)\2/', function ($m) {
        $parts = array_map(fn($v) => ($v[1] ?? '') !== '' ? $v[1] : $v[2] . $v[3], [$m]) ?? [];
        return '[' . $parts[0] . ']';
    }, $text) ?? $text;
}

function selectionLeft(array $m, int &$calls): ?string
{
    $calls++;
    return ($m[1] ?? '') !== '' ? $m[1] : null;
}

function selectionFallback(int &$calls): string
{
    $calls++;
    return 'fallback';
}

function main()
{
    $text = 'X q-mid-q Z';
    var_dump(arrowSelection($text), shorthandSelection($text), nestedSelection($text));
    $leftCalls = 0;
    $rightCalls = 0;
    $result = preg_replace_callback('/(X)|(q)(.+?)\2/', function ($m) use (&$leftCalls, &$rightCalls) {
        return '[' . (selectionLeft($m, $leftCalls) ?? selectionFallback($rightCalls)) . ']';
    }, $text) ?? $text;
    var_dump($result, $leftCalls, $rightCalls);

    $calls = 0;
    var_dump((selectionFallback($calls) ?? 'inner') ?? 'outer', $calls);
    $calls = 0;
    var_dump((selectionFallback($calls) ?: 'inner') ?? 'outer', $calls);
}
?>
--EXPECT--
string(14) "[X] <q-mid-> Z"
string(14) "[X] [q-mid-] Z"
string(14) "[X] [q-mid-] Z"
string(16) "[X] [fallback] Z"
int(2)
int(1)
string(8) "fallback"
int(1)
string(8) "fallback"
int(1)

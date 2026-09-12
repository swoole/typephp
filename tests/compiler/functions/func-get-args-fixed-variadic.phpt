--TEST--
func_get_args retains fixed parameters before the variadic tail
--FILE--
<?php
function fixedAndTail($head, ...$tail): array { return func_get_args(); }
function multipleFixed($first, $second, ...$tail): array { return func_get_args(); }
function onlyTail(...$tail): array { return func_get_args(); }
function changedFixed(&$head, ...$tail): array { $head = 7; return func_get_args(); }
function main(): void {
    echo json_encode(fixedAndTail(1,2,3)), "\n";
    echo json_encode(fixedAndTail(1)), "\n";
    echo json_encode(multipleFixed('a','b',3,4)), "\n";
    echo json_encode(onlyTail(1,2)), "\n";
    $head = 1;
    echo json_encode(changedFixed($head,2,3)), ':', $head, "\n";
}
--EXPECT--
[1,2,3]
[1]
["a","b",3,4]
[1,2]
[7,2,3]:7

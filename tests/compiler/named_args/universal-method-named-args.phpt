--TEST--
Universal methods backed by PHP functions accept named arguments
--FILE--
<?php

function trace(string $label, string $value): string
{
    echo $label, "\n";
    return $value;
}

function main(): void
{
    $s = 'a-b-c';
    echo $s->replace(replace: '+', search: '-'), "\n";
    echo $s->pad(8, pad_type: STR_PAD_LEFT), "|\n";
    echo $s->split(separator: '-', limit: 2)[1], "\n";
    echo 'abc'->hash(algo: 'crc32b'), "\n";

    // Operands are evaluated in source order, not parameter order.
    echo $s->replace(replace: trace('replace', '+'), search: trace('search', '-')), "\n";

    $list = ['b', 'a', 'c'];
    $list->sort(flags: SORT_STRING);
    echo implode(',', $list), "\n";

    $data = '{"k":1}'->jsonDecode(flags: 0);
    echo $data['k'], "\n";
}
?>
--EXPECT--
a+b+c
   a-b-c|
b-c
352441c2
replace
search
a+b+c
a,b,c
1

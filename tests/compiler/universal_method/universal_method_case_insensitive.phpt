--TEST--
Universal, extension and keyword extension methods ignore letter case
--FILE--
<?php

#[MethodsFor('*')]
final class KeywordExtensions
{
    public static function inspectValue(mixed $value, string $prefix): string
    {
        return $prefix . ':' . $value;
    }
}

#[MethodsFor(Type::String)]
final class StringExtensions
{
    public static function shout(string $value): string
    {
        return strtoupper($value) . '!';
    }
}

function main(): void
{
    $n = 7;
    echo $n->INSPECTVALUE('k'), ' ', $n->inspectvalue('k'), "\n";

    $s = 'abc';
    echo $s->SHOUT(), ' ', $s->Shout(), "\n";

    echo $s->STARTSWITH('a') ? 'yes' : 'no', "\n";
    echo $s->LENGTH(), "\n";
    echo $n->ADD(3), ' ', $n->Mul(2), "\n";

    $list = [3, 1, 2];
    $list->SORT();
    echo implode(',', $list), "\n";
    $list->Push(9);
    echo implode(',', $list), "\n";
}
?>
--EXPECT--
k:7 k:7
ABC! ABC!
yes
3
10 14
1,2,3
1,2,3,9

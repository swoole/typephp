<?php
#[MethodsFor('*')]
final class KeywordExtensions
{
    public static function TOINT(mixed $value): int
    {
        return 1;
    }
}

function main()
{
    $n = 1;
    $n->toInt();
}

<?php

function foreachDestructuringNativeReuse(array $rows): void
{
    $integer = 0;
    $floating = 0.0;
    $boolean = false;
    $string = '';
    foreach ($rows as [$integer, $floating, $boolean, $string]) {
        var_dump($integer, $floating, $boolean, $string);
    }
    foreach ($rows as ['nested' => [$integer]]) {
        var_dump($integer);
    }
}

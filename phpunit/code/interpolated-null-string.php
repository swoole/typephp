<?php

function interpolated_null_string(): string
{
    $value = 'value';
    return "before\0{$value}after\0";
}

function main(): void
{
    echo bin2hex(interpolated_null_string()), "\n";
}

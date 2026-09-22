--TEST--
String interpolation preserves embedded NUL bytes in literal parts
--FILE--
<?php

function main(): void
{
    $value = 'X';
    echo bin2hex("A\0B{$value}C\0D"), "\n";
    echo bin2hex("\0{$value}\0"), "\n";
    echo bin2hex("{$value}\x007"), "\n";

    $binary = "X\0Y";
    echo bin2hex("A{$binary}B"), "\n";

    $heredoc = <<<TEXT
    A\0B{$value}C\0D
    TEXT;
    echo bin2hex($heredoc), "\n";
}
?>
--EXPECT--
41004258430044
005800
580037
4158005942
41004258430044

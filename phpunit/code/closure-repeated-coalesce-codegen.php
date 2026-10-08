<?php

function repeatedCoalesceClosure(string $text): string
{
    return preg_replace_callback('/(X)|(q)(.+?)\2/', function ($m) {
        if (($m[1] ?? '') !== '') {
            return '[' . $m[1] . ']';
        }
        return '<' . $m[2] . $m[3] . '>';
    }, $text) ?? $text;
}

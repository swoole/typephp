--TEST--
Closure ?? guard stays correct when an outer ?? re-parses the callback call
--FILE--
<?php

function renderRepeatedCoalesce(string $text): string
{
    return preg_replace_callback('/(X)|(q)(.+?)\2/', function ($m) {
        if (($m[1] ?? '') !== '') {
            return '[' . $m[1] . ']';
        }
        return '<' . $m[2] . $m[3] . '>';
    }, $text) ?? $text;
}

function main()
{
    var_dump(renderRepeatedCoalesce('X q-mid-q Z'));
}
?>
--EXPECT--
string(14) "[X] <q-mid-> Z"

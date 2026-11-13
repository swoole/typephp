<?php
function main(): string
{
    $s = 'a-b';
    $x = $s->replace(replace: '+', search: '-');
    return $x . $s->pad(6, pad_type: STR_PAD_LEFT);
}

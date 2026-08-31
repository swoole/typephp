<?php

function lowercaseIsRuntime()
{
    return php_int_max + 1;
}

function uppercaseFolds(): float
{
    return PHP_INT_MAX + 1;
}

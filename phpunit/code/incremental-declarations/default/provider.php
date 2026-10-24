<?php
namespace Incremental;

const LIMIT = 42;

function answer(): int
{
    global $shared;
    $shared = LIMIT;
    return LIMIT;
}

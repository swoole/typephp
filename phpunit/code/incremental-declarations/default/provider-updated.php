<?php
namespace Incremental;

const LIMIT = 43;

function answer(): int
{
    global $shared;
    $shared = LIMIT;
    return LIMIT;
}

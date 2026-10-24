<?php

function main(): int
{
    global $shared;
    return \Incremental\answer() + \Incremental\LIMIT;
}

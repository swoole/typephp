<?php
function main(): int
{
    $counter = new \Incremental\Counter();
    return $counter->add(1) + \Incremental\answer();
}

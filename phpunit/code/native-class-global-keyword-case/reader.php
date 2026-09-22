<?php

#[Native]
class NativeCaseValue
{
    public int $value = 42;
}

function readCaseSlot(): int
{
    global $caseSlot;
    return $caseSlot->value;
}

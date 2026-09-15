<?php
/**
 * Isolated fixture: a Closure parameter written by the body.
 *
 * PHP allows re-assigning a parameter to any other type, so a parameter
 * narrowed from its call sites would truncate the value (float stored into an
 * int) or fail to compile. Only a parameter the body never writes to may be
 * narrowed.
 */

function reassignToString(): void
{
    $fn = function ($rwStr) {
        $rwStr = "str";
        return $rwStr;
    };
    var_dump($fn(42));
}

function reassignToFloat(): void
{
    $fn = function ($rwFloat) {
        $rwFloat = 1.5;
        return $rwFloat;
    };
    var_dump($fn(42));
}

function incrementParam(): void
{
    $fn = function ($rwInc) {
        $rwInc++;
        return $rwInc;
    };
    var_dump($fn(42));
}

function writeThroughArrayDim(): void
{
    $fn = function ($rwDim) {
        $rwDim[] = 9;
        return $rwDim;
    };
    var_dump($fn([1, 2]));
}

// A parameter the body only reads still narrows.
function untouchedParamStillNarrows(): void
{
    $fn = fn($roKeep) => $roKeep + 1;
    var_dump($fn(42));
}

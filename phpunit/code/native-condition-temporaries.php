<?php

function nativeCondition(array $values): int
{
    if ($values[0] === 1) {
        return 1;
    }
    if ($values[0] === 2 || $values[1] === 3) {
        return 2;
    }
    return 0;
}

function dynamicConditionValue(mixed $value): mixed
{
    return $value;
}

function dynamicCondition(array $values): bool
{
    if (dynamicConditionValue($values[0])) {
        return true;
    }
    return false;
}

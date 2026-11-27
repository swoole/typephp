<?php

function nested_identity(bool $ready, int $index, int $limit): bool
{
    return $ready === ($index === $limit);
}

function main(): void
{
    var_dump(nested_identity(false, 1, 120));
}

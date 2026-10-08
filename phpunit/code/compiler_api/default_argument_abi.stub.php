<?php

const EXPORTED_ABI_INT = 42;
const EXPORTED_ABI_STRING = 'internal';
const EXPORTED_ABI_ARRAY = ['mode' => 'fast'];

function exported_defaults(
    string $text = 'hello',
    array $options = ['mode' => 'fast'],
    mixed $value = null,
    int $count = 0,
    bool $enabled = false
): array {}

function exported_variadic(string ...$values): array {}

function exported_storage_defaults(
    string $literal = 'hello',
    string $constant = EXPORTED_ABI_STRING,
    string $joined = 'hello' . 'world',
    string $className = \stdClass::class,
    int $mask = 1 | \ArrayObject::ARRAY_AS_PROPS,
    string $selectedLiteral = true ? 'yes' : 'no',
    string $selectedConstant = true ? EXPORTED_ABI_STRING : EXPORTED_ABI_STRING,
): void {}

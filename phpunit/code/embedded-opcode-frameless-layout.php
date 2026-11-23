<?php

require $argv[1];

// Simulate a CLI/embed frameless-table mismatch without changing the host PHP
// installation. Both substituted handlers take two strings and return bool.
$library = PHP_OS_FAMILY === 'Windows'
    ? dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_ZTS ? 'php8ts.dll' : 'php8.dll')
    : null;
$ffi = FFI::cdef(<<<'C'
typedef struct { uint32_t refcount; uint32_t type_info; uintptr_t hash; size_t length; char value[1]; } ZendString;
typedef struct { unsigned char type; unsigned char arg_flags[3]; uint32_t flags; ZendString *name; } ZendFunction;
extern ZendFunction **zend_flf_functions;
extern void **zend_flf_handlers;
C, $library);
$contains = $starts = null;
for ($index = 0; !FFI::isNull($ffi->zend_flf_functions[$index]); ++$index) {
    $name = $ffi->zend_flf_functions[$index]->name;
    $text = FFI::string(FFI::addr($name->value[0]), $name->length);
    if ($text === 'str_contains') {
        $contains = $index;
    } elseif ($text === 'str_starts_with') {
        $starts = $index;
    }
}
if ($contains === null || $starts === null) {
    throw new RuntimeException('Missing frameless string handlers');
}
$original = $ffi->cast('uintptr_t', $ffi->zend_flf_handlers[$contains])->cdata;
$ffi->zend_flf_handlers[$contains] = $ffi->zend_flf_handlers[$starts];
try {
    $result = embeddedBuiltinCalls('AbZ');
} finally {
    $ffi->zend_flf_handlers[$contains] = $ffi->cast('void *', $original);
}
echo json_encode($result);

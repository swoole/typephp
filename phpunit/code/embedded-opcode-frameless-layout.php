<?php

// Simulate a CLI/embed frameless-table mismatch without changing the host PHP
// installation. Only run the simulation after proving that the generated
// opcodes were loaded, rather than recompiling the source in the reader.
$library = PHP_OS_FAMILY === 'Windows'
    ? dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_ZTS ? 'php8ts.dll' : 'php8.dll')
    : null;
$ffi = FFI::cdef(<<<'C'
typedef struct { uint32_t refcount; uint32_t type_info; uintptr_t hash; size_t length; char value[1]; } ZendString;
typedef struct { unsigned char type; unsigned char arg_flags[3]; uint32_t flags; ZendString *name; } ZendFunction;
extern ZendFunction **zend_flf_functions;
extern void **zend_flf_handlers;
extern char zend_system_id[32];
C, $library);

$header = file_get_contents($argv[2], false, null, 0, 40);
if (strlen($header) !== 40 || substr($header, 0, 8) !== "OPCACHE\0") {
    throw new RuntimeException('Invalid test opcode cache header');
}
// TypePHP's loader intentionally ignores this identifier: build OPcache hooks
// and runtime hooks differ. Mirror that policy in a test-only cache copy for
// Zend's stricter reader. Both processes use the same PHP_BINARY and ABI.
$systemId = FFI::string($ffi->zend_system_id, 32);
$cachedSystemId = substr($header, 8, 32);
if ($systemId !== $cachedSystemId) {
    $runtimeCache = str_replace(
        '/' . $cachedSystemId . '/', '/' . $systemId . '/', str_replace('\\', '/', $argv[2]), $replacements,
    );
    if ($replacements !== 1) {
        throw new RuntimeException('Invalid test opcode cache path');
    }
    $directory = dirname($runtimeCache);
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $bytes = file_get_contents($argv[2]);
    file_put_contents($runtimeCache, substr_replace($bytes, $systemId, 8, 32));
}
$origin = require $argv[1];
if ($origin !== 'cached') {
    throw new RuntimeException('OPcache did not replay the generated cache');
}

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
$indices = [$contains, $starts];
$handlers = [];
foreach ($indices as $index) {
    $handlers[] = $ffi->cast('uintptr_t', $ffi->zend_flf_handlers[$index])->cdata;
}
foreach ($indices as $position => $index) {
    $ffi->zend_flf_handlers[$index] = $ffi->cast('void *', $handlers[1 - $position]);
}
try {
    $result = embeddedBuiltinCalls('AbZ');
} finally {
    foreach ($indices as $position => $index) {
        $ffi->zend_flf_handlers[$index] = $ffi->cast('void *', $handlers[$position]);
    }
}
echo json_encode($result);

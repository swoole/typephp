#!/usr/bin/env php
<?php
// A compiler substitute for the canary only. It uses the sanitizer option
// actually delivered by run-tests.php to build one faulty C++ translation unit.
$source = $argv[1];
$output = str_replace('-', '_', basename($source, '.php'));
$sanitize = '';
for ($i = 2; $i < $argc; $i++) {
    if ($argv[$i] === '--sanitize') {
        $sanitize = $argv[++$i];
    } elseif ($argv[$i] === '--output') {
        $output = $argv[++$i];
    }
}
if ($sanitize !== 'address') {
    fwrite(STDERR, "Expected the harness to force --sanitize address\n");
    exit(1);
}
$command = [getenv('CXX') ?: 'g++', '-fsanitize=' . $sanitize,
    '-g', '-O1', '-fno-omit-frame-pointer', __DIR__ . '/overflow.cc', '-o', $output];
passthru(implode(' ', array_map('escapeshellarg', $command)), $status);
exit($status);

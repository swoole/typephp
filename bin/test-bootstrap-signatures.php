#!/usr/bin/env php
<?php

// The enclosing build launcher supplies resource limits. CI supplies its CPU
// count through PHP_COMPILER_JOBS; local invocations default to one compiler.
$root = dirname(__DIR__);
$options = getopt('', ['compiler:']);
$compiler = realpath($options['compiler'] ?? $root . (PHP_OS_FAMILY === 'Windows' ? '/tpc.exe' : '/tpc'));
if ($compiler === false || !is_executable($compiler)) {
    throw new RuntimeException('A built compiler is required; use --compiler=/path/to/tpc');
}
$artifacts = $root . '/build/bootstrap-signatures-' . PHP_VERSION;
if (!is_dir($artifacts) && !mkdir($artifacts, 0777, true)) {
    throw new RuntimeException('Cannot create bootstrap regression artifact directory');
}
$target = $artifacts . '/bootstrap_signatures' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
$command = str_ends_with($compiler, '.php') ? [PHP_BINARY, $compiler] : [$compiler];
array_push(
    $command, $root . '/.github/integration/bootstrap-signatures/project.yml',
    '--output', $target, '--build-dir', $artifacts . '/build',
    '--job', getenv('PHP_COMPILER_JOBS') ?: '1', '--no-progress',
);
foreach ([['compile', $command], ['run', [$target]]] as [$phase, $arguments]) {
    $log = $artifacts . '/' . $phase . '.log';
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start bootstrap regression ' . $phase);
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $output = file_get_contents($log);
    if ($exit !== 0 || ($phase === 'run' && trim($output) !== 'bootstrap-signatures-ok')) {
        fwrite(STDERR, $output);
        throw new RuntimeException('Bootstrap signature regression failed: ' . $log . ' (exit ' . $exit . ')');
    }
}
echo "PASS bootstrap signatures: compiled interface, trait self, request-time autoload, diagnostic exception\n";

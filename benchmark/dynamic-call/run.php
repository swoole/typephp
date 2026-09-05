<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = __DIR__ . '/benchmark.php';
$project = __DIR__ . '/project.yml';
$binary = __DIR__ . '/dynamic_call' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
$skipBuild = in_array('--skip-build', $argv, true);
$selectedCase = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--case=')) {
        $selectedCase = substr($argument, strlen('--case='));
    }
}

/**
 * @param list<string> $command
 * @param array<string, string>|null $environment
 */
function runDynamicCallCommand(array $command, string $cwd, bool $capture, ?array $environment = null): string
{
    $stdout = $capture ? ['pipe', 'w'] : STDOUT;
    $stderr = $capture ? ['pipe', 'w'] : STDERR;
    $process = proc_open(
        $command,
        [STDIN, $stdout, $stderr],
        $pipes,
        $cwd,
        $environment,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start: ' . implode(' ', $command));
    }

    $output = '';
    $error = '';
    if ($capture) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
    }
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException(
            'Command failed (' . $status . '): ' . implode(' ', $command) . "\n" . $output . $error,
        );
    }
    return $output;
}

/** @return array{metrics: array<string, float>, checksums: array<string, string>} */
function parseDynamicCallResults(string $output): array
{
    $metrics = [];
    $checksums = [];
    foreach (explode("\n", trim($output)) as $line) {
        if (preg_match('/^([a-z_]+)_ns=([0-9.]+)$/', $line, $matches)) {
            $metrics[$matches[1]] = (float) $matches[2];
        } elseif (preg_match('/^checksum_([a-z_]+)=(-?[0-9]+)$/', $line, $matches)) {
            $checksums[$matches[1]] = $matches[2];
        }
    }
    return ['metrics' => $metrics, 'checksums' => $checksums];
}

$baselinePhp = getenv('PHP_BIN') ?: PHP_BINARY;
$compilerPhp = getenv('TPC_PHP_BIN') ?: $baselinePhp;
$runtimeProbe = static fn (string $binary): string => trim(runDynamicCallCommand([
    $binary,
    '-n',
    '-r',
    'printf("%s;%d;%d;%d", PHP_VERSION, PHP_ZTS, PHP_DEBUG, PHP_INT_SIZE);',
], $root, true));
$baselineRuntime = $runtimeProbe($baselinePhp);
$compilerRuntime = $runtimeProbe($compilerPhp);
if ($baselineRuntime !== $compilerRuntime) {
    throw new RuntimeException(
        "PHP_BIN and TPC_PHP_BIN must use the same PHP ABI for comparable results\n"
        . "PHP_BIN={$baselineRuntime}\nTPC_PHP_BIN={$compilerRuntime}",
    );
}
if (!$skipBuild) {
    echo "Building TypePHP benchmark (-O3 + LTO)...\n";
    runDynamicCallCommand([
        $compilerPhp,
        '-n',
        $root . '/bin/tpc.php',
        $project,
        '-j',
        '8',
        '--no-color',
        '--no-progress',
    ], $root, false);
}
if (!is_file($binary)) {
    throw new RuntimeException('Benchmark binary does not exist: ' . $binary);
}

$environment = getenv();
if ($selectedCase !== null && $selectedCase !== '') {
    $environment['DYNAMIC_CALL_CASE'] = $selectedCase;
}

$php = parseDynamicCallResults(runDynamicCallCommand([
    $baselinePhp,
    '-n',
    '-d',
    'opcache.enable_cli=0',
    '-d',
    'opcache.jit=0',
    '-r',
    'require ' . var_export($source, true) . '; main();',
], $root, true, $environment));

if (PHP_OS_FAMILY !== 'Windows') {
    $phpxHome = getenv('PHPX_HOME') ?: dirname($root) . '/phpx';
    $loaderVariable = PHP_OS_FAMILY === 'Darwin' ? 'DYLD_LIBRARY_PATH' : 'LD_LIBRARY_PATH';
    $existing = $environment[$loaderVariable] ?? '';
    $phpHome = dirname(dirname(realpath($compilerPhp) ?: $compilerPhp));
    $environment[$loaderVariable] = $phpxHome . '/lib' . PATH_SEPARATOR . $phpHome . '/lib'
        . ($existing === '' ? '' : PATH_SEPARATOR . $existing);
}
$typephp = parseDynamicCallResults(runDynamicCallCommand([$binary], $root, true, $environment));

$cases = [
    'direct',
    'string_monomorphic_zero',
    'string_monomorphic',
    'string_monomorphic_two',
    'string_monomorphic_four',
    'string_alternating',
    'string_megamorphic',
    'closure_monomorphic',
    'closure_alternating',
    'static_method_string',
    'static_class_dynamic',
    'static_method_dynamic',
    'static_class_method_dynamic',
    'static_class_alternating',
    'static_method_alternating',
    'static_class_method_alternating',
    'object_method_array',
    'invokable_object',
    'method_name_monomorphic',
    'method_name_alternating',
    'method_receiver_polymorphic',
    'named_method_dynamic_receiver_zero',
    'named_method_dynamic_receiver',
    'named_method_polymorphic_receiver',
    'scoped_method_name_zero',
    'scoped_method_name',
    'scoped_named_dynamic_receiver',
];
if ($selectedCase !== null && $selectedCase !== '') {
    $cases = [$selectedCase];
}

echo "Runtime: {$baselineRuntime}\n";
echo "Metric                    PHP ns/op  TypePHP ns/op  TypePHP/PHP\n";
echo "--------------------------------------------------------------\n";
foreach ($cases as $case) {
    if (!isset($php['metrics'][$case], $typephp['metrics'][$case])) {
        throw new RuntimeException("Missing benchmark metric: {$case}");
    }
    if (($php['checksums'][$case] ?? null) !== ($typephp['checksums'][$case] ?? null)) {
        throw new RuntimeException("Checksum mismatch for benchmark case: {$case}");
    }
    printf(
        "%-25s %10.2f %14.2f %12.2fx\n",
        $case,
        $php['metrics'][$case],
        $typephp['metrics'][$case],
        $typephp['metrics'][$case] / $php['metrics'][$case],
    );
}

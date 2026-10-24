#!/usr/bin/env php
<?php

// Run under the platform's build lock and process-tree resource limit.
// Compiler invocations are synchronous; local builds default to one job.
// CI supplies its runner CPU count through PHP_COMPILER_JOBS.
$root = dirname(__DIR__);
$suite = $root . '/.github/integration/swoole-thread';
$options = getopt('', ['artifacts:', 'modes:', 'compiler:', 'cxx:', 'no-literal-strings', 'without-exceptions']);
$artifacts = $options['artifacts'] ?? $root . '/build/swoole-thread-' . PHP_OS_FAMILY;
if (!is_dir($artifacts) && !mkdir($artifacts, 0777, true)) {
    throw new RuntimeException('Cannot create artifact directory');
}
$artifacts = realpath($artifacts);
$report = [
    'platform' => PHP_OS_FAMILY, 'system' => php_uname(), 'php' => PHP_VERSION,
    'zts' => (bool) PHP_ZTS, 'swoole' => phpversion('swoole'),
    'literal_strings' => !isset($options['no-literal-strings']),
    'exceptions' => !isset($options['without-exceptions']), 'cases' => [],
];
$exit = 0;

function threadTestCommand(array $command, string $log, int $timeout = 180, array $env = []): array
{
    $environment = array_replace(getenv(), $env);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start ' . $command[0]);
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + $timeout;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($process);
            usleep(200000);
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
            throw new RuntimeException('Timed out: ' . $log);
        }
        usleep(20000);
    } while (true);
    proc_close($process);
    return ['exit' => $status['exitcode'], 'output' => file_get_contents($log)];
}

try {
    if (!PHP_ZTS || !class_exists(Swoole\Thread::class)) {
        throw new RuntimeException('Requires PHP ZTS and Swoole built with thread support');
    }
    foreach (explode(',', $options['modes'] ?? 'control,ext,bin,bin-embedded') as $mode) {
        if (!in_array($mode, ['control', 'ext', 'bin', 'bin-embedded'], true)) {
            throw new RuntimeException('Unknown mode: ' . $mode);
        }
        $record = ['mode' => $mode, 'passed' => false];
        try {
            if ($mode === 'control') {
                $command = [PHP_BINARY, $suite . '/host.php'];
                $env = ['TYPEPHP_THREAD_CONTROL' => '1'];
            } else {
                $suffix = $mode === 'ext' ? PHP_SHLIB_SUFFIX : (PHP_OS_FAMILY === 'Windows' ? 'exe' : 'bin');
                $target = $artifacts . '/thread_' . $mode . '.' . $suffix;
                $compiler = $options['compiler'] ?? $suite . '/source-compiler.php';
                $project = $suite . '/' . $mode . '.yml';
                if ($mode === 'bin-embedded') {
                    // Remove only a private fixture copy. The runtime must read
                    // the embedded resource after its disk file is gone.
                    $projectDir = $artifacts . '/embedded-project';
                    if (!is_dir($projectDir)) {
                        mkdir($projectDir, 0777, true);
                    }
                    foreach (['bin-embedded.yml', 'probe.php', 'bin-main.php', 'host.php', 'host-symbols.php', 'worker.php', 'request.txt'] as $fixture) {
                        copy($suite . '/' . $fixture, $projectDir . '/' . $fixture);
                    }
                    $project = $projectDir . '/bin-embedded.yml';
                }
                $command = str_ends_with($compiler, '.php') ? [PHP_BINARY, $compiler] : [$compiler];
                array_push($command, $project, '--output', $target, '--build-dir', $artifacts . '/build-' . $mode, '--job', getenv('PHP_COMPILER_JOBS') ?: '1', '--no-progress');
                if (isset($options['cxx'])) {
                    array_push($command, '--compiler', $options['cxx']);
                }
                if (isset($options['no-literal-strings'])) {
                    $command[] = '--no-literal-strings';
                }
                $build = threadTestCommand($command, $artifacts . '/' . $mode . '-build.log', 600, ['TYPEPHP_THREAD_CONTROL' => '0']);
                $record['build_exit'] = $build['exit'];
                if ($build['exit'] !== 0 || !is_file($target)) {
                    throw new RuntimeException('Build failed: ' . $artifacts . '/' . $mode . '-build.log');
                }
                if ($mode === 'bin-embedded' && !unlink($projectDir . '/request.txt')) {
                    throw new RuntimeException('Cannot remove private embedded-resource fixture');
                }
                $command = $mode === 'ext'
                    ? [PHP_BINARY, '-d', 'extension=' . $target, $suite . '/host.php']
                    : [$target];
                $env = ['TYPEPHP_THREAD_CONTROL' => '0'];
            }
            $trace = $artifacts . '/' . $mode . '-trace.log';
            file_put_contents($trace, '');
            $env['TYPEPHP_THREAD_TRACE'] = $trace;
            $env['TYPEPHP_THREAD_EXCEPTIONS'] = isset($options['without-exceptions']) ? '0' : '1';
            $env['TYPEPHP_THREAD_EMBEDDED'] = $mode === 'bin-embedded' ? '1' : '0';
            $run = threadTestCommand($command, $artifacts . '/' . $mode . '-run.log', 45, $env);
            $record['runtime_exit'] = $run['exit'];
            $runtime = json_decode($run['output'], true);
            if ($run['exit'] !== 0 || !is_array($runtime)
                || ($runtime['status'] ?? '') !== 'swoole-thread-ok'
                || ($runtime['snapshots'] ?? 0) !== 54 || ($runtime['shutdowns'] ?? 0) !== 9
                || ($runtime['zts'] ?? false) !== true
                || ($runtime['embedded_resource'] ?? false) !== ($mode === 'bin-embedded')) {
                throw new RuntimeException('Runtime failed: ' . $artifacts . '/' . $mode . '-run.log');
            }
            $record['runtime'] = $runtime;
            $record['passed'] = true;
            echo 'PASS ', $mode, PHP_EOL;
        } catch (Throwable $error) {
            $record['error'] = $error->getMessage();
            $exit = 1;
            echo 'FAIL ', $mode, ': ', $error->getMessage(), PHP_EOL;
        }
        $report['cases'][] = $record;
        file_put_contents($artifacts . '/results.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        if (!$record['passed']) {
            break;
        }
    }
} catch (Throwable $error) {
    $report['error'] = $error->getMessage();
    $exit = 1;
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
}
file_put_contents($artifacts . '/results.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($exit);

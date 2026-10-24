#!/usr/bin/env php
<?php


namespace TypePhp\IntegrationTest;

use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

const TYPEPHP_INTEGRATION_ROOT = __DIR__ . '/..';
const TYPEPHP_INTEGRATION_TEST_ROOT = TYPEPHP_INTEGRATION_ROOT . '/.github/integration';

final class IntegrationFailure extends RuntimeException
{
}

/** @return array{compiler: string, compiler_ini_scan_dir: string, php: string, php_fpm: string, keep: bool, suite: string, fpm_php_version: string} */
function parseIntegrationOptions(array $argv): array
{
    $options = [
        'compiler' => TYPEPHP_INTEGRATION_ROOT . '/tpc',
        'compiler_ini_scan_dir' => '',
        'php' => PHP_BINARY,
        'php_fpm' => '',
        'keep' => false,
        'suite' => 'all',
        'fpm_php_version' => '8.5',
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--keep') {
            $options['keep'] = true;
            continue;
        }
        if (str_starts_with($argument, '--suite=')) {
            $options['suite'] = substr($argument, strlen('--suite='));
            continue;
        }
        foreach (['compiler', 'compiler-ini-scan-dir', 'php', 'php-fpm', 'fpm-php-version'] as $name) {
            $prefix = '--' . $name . '=';
            if (str_starts_with($argument, $prefix)) {
                $key = str_replace('-', '_', $name);
                $options[$key] = substr($argument, strlen($prefix));
                continue 2;
            }
        }
        throw new IntegrationFailure('Unknown option: ' . $argument);
    }

    if (!in_array($options['suite'], ['all', 'ext', 'lib', 'fpm'], true)) {
        throw new IntegrationFailure('Invalid --suite value; expected all, ext, lib, or fpm');
    }
    if (!in_array($options['fpm_php_version'], ['8.4', '8.5'], true)) {
        throw new IntegrationFailure('Invalid --fpm-php-version value; expected 8.4 or 8.5');
    }

    foreach (['compiler', 'php'] as $name) {
        $path = realpath($options[$name]);
        if ($path === false || !is_executable($path)) {
            throw new IntegrationFailure("{$name} is not executable: {$options[$name]}");
        }
        $options[$name] = $path;
    }

    if ($options['compiler_ini_scan_dir'] !== '') {
        $path = realpath($options['compiler_ini_scan_dir']);
        if ($path === false || !is_dir($path)) {
            throw new IntegrationFailure(
                'compiler ini scan directory does not exist: ' . $options['compiler_ini_scan_dir'],
            );
        }
        $options['compiler_ini_scan_dir'] = $path;
    }

    if (in_array($options['suite'], ['all', 'ext'], true)) {
        if ($options['php_fpm'] === '') {
            $prefix = integrationPhpPrefix($options['php']);
            $candidates = [
                $prefix . '/sbin/php-fpm',
                dirname($options['php']) . '/php-fpm',
                dirname($options['php']) . '/php-fpm' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            ];
            foreach ($candidates as $candidate) {
                if (is_executable($candidate)) {
                    $options['php_fpm'] = $candidate;
                    break;
                }
            }
        }

        $fpm = realpath($options['php_fpm']);
        if ($fpm === false || !is_executable($fpm)) {
            throw new IntegrationFailure(
                'A PHP-FPM binary from the same PHP installation is required; pass --php-fpm=/path/to/php-fpm',
            );
        }
        $options['php_fpm'] = $fpm;
    }

    return $options;
}

function integrationPhpPrefix(string $php): string
{
    $phpConfig = dirname($php) . '/php-config';
    if (!is_executable($phpConfig)) {
        return dirname(dirname($php));
    }
    $result = runIntegrationCommand([$phpConfig, '--prefix'], null, [], 15, false);
    if ($result['exit_code'] !== 0) {
        return dirname(dirname($php));
    }
    return trim($result['stdout']);
}

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function runIntegrationCommand(
    array $command,
    ?string $workingDirectory = null,
    array $environment = [],
    int $timeout = 180,
    bool $throwOnFailure = true,
): array {
    fwrite(STDOUT, '$ ' . implode(' ', array_map('escapeshellarg', $command)) . PHP_EOL);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $workingDirectory,
        array_replace(integrationEnvironment(), $environment),
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new IntegrationFailure('Failed to start command');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $startedAt = microtime(true);
    $exitCode = -1;

    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        if (microtime(true) - $startedAt > $timeout) {
            proc_terminate($process);
            usleep(200_000);
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            $stderr .= "\nCommand timed out after {$timeout} seconds\n";
            break;
        }
        usleep(20_000);
    }

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if ($stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }
    if ($throwOnFailure && $exitCode !== 0) {
        throw new IntegrationFailure('Command failed with exit code ' . $exitCode);
    }

    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

/** @return array<string, string> */
function integrationEnvironment(): array
{
    return getenv();
}

/**
 * @param array{compiler_ini_scan_dir: string} $options
 * @return array<string, string>
 */
function integrationCompilerEnvironment(array $options): array
{
    if ($options['compiler_ini_scan_dir'] === '') {
        return [];
    }
    return ['PHP_INI_SCAN_DIR' => $options['compiler_ini_scan_dir']];
}

function assertIntegrationSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new IntegrationFailure(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true),
        );
    }
}

function assertIntegrationTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new IntegrationFailure($message);
    }
}

/**
 * @param int|null $expectedPid
 * @param-out int $expectedPid
 */
function assertLifecycleBody(string $body, int $request, ?int &$expectedPid, string $host): void
{
    $kind = $request % 2 === 0 ? 'even' : 'odd';
    try {
        $actual = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new IntegrationFailure("{$host} returned invalid JSON: {$body}", previous: $error);
    }
    $pid = $actual['pid'] ?? 0;
    unset($actual['pid']);
    $expected = [
        'request' => $request,
        'results' => [
            "1@{$request}|{$kind}-handler[{$kind}:{$request}]",
            "2@{$request}|{$kind}-handler[{$kind}:{$request}]",
        ],
        'peer_results' => [
            "peer-1@{$request}|{$kind}-handler[{$kind}:{$request}]",
            "peer-2@{$request}|{$kind}-handler[{$kind}:{$request}]",
        ],
        'extensions_loaded' => [true, true],
        'main_registered' => false,
    ];
    if ($actual !== $expected) {
        throw new IntegrationFailure(
            "{$host} request {$request} failed\nExpected: " . var_export($expected, true)
            . "\nActual:   " . var_export($actual, true),
        );
    }
    assertIntegrationTrue(is_int($pid) && $pid > 0, "{$host} did not return a valid worker PID");
    if ($expectedPid !== null && $expectedPid !== $pid) {
        throw new IntegrationFailure("{$host} worker restarted between requests: {$expectedPid} -> {$pid}");
    }
    $expectedPid = $pid;
}

/**
 * @param array<string, string> $environment
 * @return array{process: resource, pipes: array<int, resource>}
 */
function startIntegrationProcess(array $command, ?string $workingDirectory = null, array $environment = []): array
{
    fwrite(STDOUT, '$ ' . implode(' ', array_map('escapeshellarg', $command)) . PHP_EOL);
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory,
        array_replace(integrationEnvironment(), $environment),
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new IntegrationFailure('Failed to start server process');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['process' => $process, 'pipes' => $pipes];
}

/** @param array{process: resource, pipes: array<int, resource>} $server */
function stopIntegrationProcess(array $server): string
{
    $process = $server['process'];
    $status = proc_get_status($process);
    if ($status['running']) {
        proc_terminate($process);
        $deadline = microtime(true) + 3;
        do {
            usleep(50_000);
            $status = proc_get_status($process);
        } while ($status['running'] && microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
    }
    $output = stream_get_contents($server['pipes'][1]) . stream_get_contents($server['pipes'][2]);
    fclose($server['pipes'][1]);
    fclose($server['pipes'][2]);
    proc_close($process);
    return $output;
}

function reserveIntegrationPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new IntegrationFailure("Cannot reserve TCP port: {$errorMessage} ({$errorCode})");
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if ($address === false || !preg_match('/:(\d+)$/', $address, $matches)) {
        throw new IntegrationFailure('Cannot determine reserved TCP port');
    }
    return (int) $matches[1];
}

function requestHttp(int $port, int $request, float $timeout = 2.0): string
{
    $socket = @stream_socket_client(
        "tcp://127.0.0.1:{$port}",
        $errorCode,
        $errorMessage,
        $timeout,
    );
    if ($socket === false) {
        throw new IntegrationFailure("HTTP connection failed: {$errorMessage} ({$errorCode})");
    }
    stream_set_timeout($socket, (int) ceil($timeout));
    fwrite(
        $socket,
        "GET /request.php?request={$request} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n",
    );
    $response = stream_get_contents($socket);
    fclose($socket);
    if (!preg_match('/^HTTP\/1\.[01] 200\b/', $response)) {
        throw new IntegrationFailure('Unexpected HTTP response: ' . $response);
    }
    $parts = preg_split("/\r?\n\r?\n/", $response, 2);
    return trim($parts[1] ?? '');
}

/** @param callable(): string $request */
function waitForIntegrationServer(array $server, callable $request): string
{
    $deadline = microtime(true) + 10;
    $lastError = null;
    do {
        $status = proc_get_status($server['process']);
        if (!$status['running']) {
            $logs = stream_get_contents($server['pipes'][1]) . stream_get_contents($server['pipes'][2]);
            throw new IntegrationFailure('Server exited during startup: ' . $logs);
        }
        try {
            return $request();
        } catch (IntegrationFailure $error) {
            $lastError = $error;
            usleep(100_000);
        }
    } while (microtime(true) < $deadline);

    throw new IntegrationFailure('Server did not become ready: ' . $lastError->getMessage());
}

function encodeFastCgiLength(int $length): string
{
    return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
}

/** @param array<string, string> $parameters */
function encodeFastCgiParameters(array $parameters): string
{
    $result = '';
    foreach ($parameters as $name => $value) {
        $result .= encodeFastCgiLength(strlen($name));
        $result .= encodeFastCgiLength(strlen($value));
        $result .= $name . $value;
    }
    return $result;
}

function fastCgiRecord(int $type, int $requestId, string $content): string
{
    $padding = (8 - strlen($content) % 8) % 8;
    return pack('CCnnCC', 1, $type, $requestId, strlen($content), $padding, 0)
        . $content . str_repeat("\0", $padding);
}

function requestFastCgi(int $port, string $script, int $request, float $timeout = 3.0): string
{
    $socket = @stream_socket_client(
        "tcp://127.0.0.1:{$port}",
        $errorCode,
        $errorMessage,
        $timeout,
    );
    if ($socket === false) {
        throw new IntegrationFailure("FastCGI connection failed: {$errorMessage} ({$errorCode})");
    }
    stream_set_timeout($socket, (int) ceil($timeout));
    $parameters = encodeFastCgiParameters([
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'SERVER_SOFTWARE' => 'typephp-integration',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => "/request.php?request={$request}",
        'SCRIPT_NAME' => '/request.php',
        'SCRIPT_FILENAME' => $script,
        'DOCUMENT_ROOT' => dirname($script),
        'QUERY_STRING' => "request={$request}",
        'REMOTE_ADDR' => '127.0.0.1',
        'REMOTE_PORT' => '12345',
        'SERVER_ADDR' => '127.0.0.1',
        'SERVER_PORT' => (string) $port,
        'SERVER_NAME' => 'localhost',
        'CONTENT_LENGTH' => '0',
    ]);
    $beginRequest = pack('nCxxxxx', 1, 0);
    fwrite($socket, fastCgiRecord(1, 1, $beginRequest));
    fwrite($socket, fastCgiRecord(4, 1, $parameters));
    fwrite($socket, fastCgiRecord(4, 1, ''));
    fwrite($socket, fastCgiRecord(5, 1, ''));

    $stdout = '';
    $stderr = '';
    while (!feof($socket)) {
        $header = fread($socket, 8);
        if ($header === '' || strlen($header) < 8) {
            break;
        }
        $record = unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved', $header);
        $content = '';
        while (strlen($content) < $record['length']) {
            $chunk = fread($socket, $record['length'] - strlen($content));
            if ($chunk === false || $chunk === '') {
                break 2;
            }
            $content .= $chunk;
        }
        if ($record['padding'] > 0) {
            fread($socket, $record['padding']);
        }
        if ($record['type'] === 6) {
            $stdout .= $content;
        } elseif ($record['type'] === 7) {
            $stderr .= $content;
        } elseif ($record['type'] === 3) {
            break;
        }
    }
    fclose($socket);

    if ($stderr !== '') {
        throw new IntegrationFailure('FastCGI stderr: ' . $stderr);
    }
    $parts = preg_split("/\r?\n\r?\n/", $stdout, 2);
    if (!str_contains($parts[0] ?? '', 'Status: 200') && !str_contains($parts[0] ?? '', 'Content-Type:')) {
        throw new IntegrationFailure('Unexpected FastCGI response: ' . $stdout);
    }
    return trim($parts[1] ?? '');
}

function runFpmIntegration(array $options, string $temporaryRoot): void
{
    fwrite(STDOUT, "\n[FPM] embedded opcode lazy superglobals and request isolation\n");
    $temporaryRoot = realpath($temporaryRoot);
    $project = $temporaryRoot . '/globals';
    copyIntegrationTree(TYPEPHP_INTEGRATION_TEST_ROOT . '/fpm/globals', $project);
    $binary = $temporaryRoot . '/globals-fpm';
    runIntegrationCommand([
        $options['compiler'], $project . '/project.yml',
        '--php-version', $options['fpm_php_version'],
        '--output', $binary, '--build-dir', $temporaryRoot . '/fpm-build',
        '--job', getenv('PHP_COMPILER_JOBS') ?: '1', '--no-progress',
    ], environment: integrationCompilerEnvironment($options), timeout: 1800);

    // FPM requires a physical primary-script path before calling the compile hook.
    // A successful response must nevertheless come from the embedded opcode.
    file_put_contents($project . '/request.php', '<?php throw new RuntimeException("Disk fallback used");');
    unlink($project . '/later.php');
    $port = reserveIntegrationPort();
    $config = $temporaryRoot . '/php-fpm.conf';
    $log = $temporaryRoot . '/php-fpm.log';
    file_put_contents($config, <<<INI
[global]
error_log = {$log}
daemonize = no
[www]
listen = 127.0.0.1:{$port}
pm = static
pm.max_children = 1
pm.max_requests = 0
clear_env = no
catch_workers_output = yes
INI);
    $server = startIntegrationProcess([
        $binary, '-n', '-d', 'auto_globals_jit=1', '-d', 'register_argc_argv=0',
        '-d', 'variables_order=EGPCS', '-d', 'request_order=GP',
        '-d', 'display_errors=1', '-d', 'log_errors=1',
        '-y', $config, '-F', '-O',
    ], environment: ['TYPEPHP_INTEGRATION_ENV' => 'embedded-globals']);
    try {
        $first = waitForIntegrationServer(
            $server, fn(): string => requestFastCgi($port, $project . '/request.php', 1),
        );
        $pid = null;
        for ($request = 1; $request <= 3; ++$request) {
            $body = $request === 1 ? $first : requestFastCgi($port, $project . '/request.php', $request);
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $pid ??= $data['pid'];
            assertIntegrationTrue($pid === $data['pid'], 'Requests must reuse the same FPM worker');
            assertIntegrationSame(json_encode([
                'server_array' => true, 'env_array' => true, 'request_array' => true,
                'method' => 'GET', 'uri' => "/request.php?request={$request}",
                'query' => (string) $request, 'request' => (string) $request,
                'env' => 'embedded-globals', 'previous' => null,
            ]), json_encode($data['initial']), 'Lazy superglobals or request isolation failed');
            assertIntegrationSame(json_encode([
                'server-preserved', 'env-preserved', 'request-preserved',
            ]), json_encode($data['later']), 'A subsequent embedded include reset superglobals');
        }
        fwrite(STDOUT, "PASS: SERVER/ENV/REQUEST, three requests in one worker, embedded include preserves mutations\n");
    } finally {
        $logs = stopIntegrationProcess($server);
        if ($logs !== '') {
            fwrite(STDOUT, $logs);
        }
    }
}

function runExtIntegration(array $options, string $temporaryRoot): void
{
    fwrite(STDOUT, "\n[EXT] build two modules and verify shared Zend host lifecycle\n");
    $extensions = [];
    foreach ([
        'primary' => 'extension.php',
        'peer' => 'peer-extension.php',
    ] as $name => $source) {
        $extension = $temporaryRoot . '/integration_ext_' . $name . '.' . PHP_SHLIB_SUFFIX;
        runIntegrationCommand([
            $options['compiler'],
            TYPEPHP_INTEGRATION_TEST_ROOT . '/ext/lifecycle/src/' . $source,
            '--mode', 'ext',
            '--output', $extension,
            '--build-dir', $temporaryRoot . '/ext-build-' . $name,
            '--job', getenv('PHP_COMPILER_JOBS') ?: '1',
            '--no-progress',
        ], environment: integrationCompilerEnvironment($options));
        assertIntegrationTrue(is_file($extension), 'Extension artifact was not generated: ' . $extension);
        $extensions[$name] = $extension;
    }

    $hostScript = realpath(TYPEPHP_INTEGRATION_TEST_ROOT . '/ext/lifecycle/host/request.php');
    if ($hostScript === false) {
        throw new IntegrationFailure('Extension host script is missing');
    }
    foreach ([$extensions, array_reverse($extensions)] as $orderIndex => $extensionOrder) {
        for ($request = 1; $request <= 2; ++$request) {
            $command = [$options['php'], '-n'];
            foreach ($extensionOrder as $extension) {
                array_push($command, '-d', 'extension=' . $extension);
            }
            $command[] = $hostScript;
            $result = runIntegrationCommand(
                $command,
                null,
                ['TYPEPHP_INTEGRATION_REQUEST' => (string) $request],
            );
            $cliPid = null;
            assertLifecycleBody(
                trim($result['stdout']),
                $request,
                $cliPid,
                'CLI extensions, load order ' . ($orderIndex + 1),
            );
        }
    }

    $serverPort = reserveIntegrationPort();
    $serverCommand = [$options['php'], '-n'];
    foreach ($extensions as $extension) {
        array_push($serverCommand, '-d', 'extension=' . $extension);
    }
    array_push(
        $serverCommand,
        '-d', 'display_errors=1', '-S', "127.0.0.1:{$serverPort}",
        '-t', dirname($hostScript),
    );
    $server = startIntegrationProcess($serverCommand);
    $serverLogs = '';
    try {
        $first = waitForIntegrationServer($server, fn(): string => requestHttp($serverPort, 1));
        $serverPid = null;
        assertLifecycleBody($first, 1, $serverPid, 'php -S');
        for ($request = 2; $request <= 8; ++$request) {
            $body = requestHttp($serverPort, $request);
            assertLifecycleBody($body, $request, $serverPid, 'php -S');
        }
    } finally {
        $serverLogs = stopIntegrationProcess($server);
        if ($serverLogs !== '') {
            fwrite(STDOUT, $serverLogs);
        }
    }

    $fpmPort = reserveIntegrationPort();
    $fpmConfig = $temporaryRoot . '/php-fpm.conf';
    $fpmLog = $temporaryRoot . '/php-fpm.log';
    file_put_contents($fpmConfig, <<<INI
[global]
daemonize = no
error_log = {$fpmLog}

[www]
listen = 127.0.0.1:{$fpmPort}
pm = static
pm.max_children = 1
pm.max_requests = 0
clear_env = no
catch_workers_output = yes

INI);
    $fpmCommand = [$options['php_fpm'], '-n'];
    foreach (array_reverse($extensions) as $extension) {
        array_push($fpmCommand, '-d', 'extension=' . $extension);
    }
    array_push(
        $fpmCommand,
        '-d', 'display_errors=1', '-d', 'log_errors=0',
        '-y', $fpmConfig, '-F', '-O',
    );
    $fpm = startIntegrationProcess($fpmCommand);
    $fpmLogs = '';
    try {
        $first = waitForIntegrationServer(
            $fpm,
            fn(): string => requestFastCgi($fpmPort, $hostScript, 1),
        );
        $fpmPid = null;
        assertLifecycleBody($first, 1, $fpmPid, 'PHP-FPM');
        for ($request = 2; $request <= 8; ++$request) {
            $body = requestFastCgi($fpmPort, $hostScript, $request);
            assertLifecycleBody($body, $request, $fpmPid, 'PHP-FPM');
        }
    } finally {
        $fpmLogs = stopIntegrationProcess($fpm);
        if ($fpmLogs !== '') {
            fwrite(STDOUT, $fpmLogs);
        }
    }
}

function copyIntegrationTree(string $source, string $destination): void
{
    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        throw new IntegrationFailure('Cannot create directory: ' . $destination);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $item) {
        $target = $destination . '/' . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0777, true);
            }
        } elseif (!copy($item->getPathname(), $target)) {
            throw new IntegrationFailure('Cannot copy fixture: ' . $item->getPathname());
        }
    }
}

function runLibIntegration(array $options, string $temporaryRoot): void
{
    fwrite(STDOUT, "\n[LIB] two providers/import stubs/one consumer boundary\n");
    $providers = [];
    foreach ([
        'integration_provider' => 'provider',
        'integration_peer' => 'peer-provider',
    ] as $target => $fixture) {
        $providerRoot = $temporaryRoot . '/' . $fixture;
        copyIntegrationTree(TYPEPHP_INTEGRATION_TEST_ROOT . '/lib/' . $fixture, $providerRoot);
        runIntegrationCommand([
            $options['compiler'], $providerRoot . '/project.yml',
            '--output', $providerRoot . '/' . $target . '.' . PHP_SHLIB_SUFFIX,
            '--build-dir', $providerRoot . '/build', '--job', getenv('PHP_COMPILER_JOBS') ?: '1', '--no-progress',
        ], environment: integrationCompilerEnvironment($options));

        $library = $providerRoot . '/' . $target . '.' . PHP_SHLIB_SUFFIX;
        $stub = $providerRoot . '/' . $target . '.stub.php';
        assertIntegrationTrue(is_file($library), 'Library artifact was not generated: ' . $library);
        assertIntegrationTrue(is_file($stub), 'Library import stub was not generated: ' . $stub);
        $stubCode = file_get_contents($stub);
        assertIntegrationTrue(
            is_string($stubCode) && str_contains($stubCode, '@import-library'),
            'Invalid library stub: ' . $stub,
        );
        assertIntegrationTrue(!str_contains($stubCode, 'function main('), 'Library stub must not export bin main()');
        assertIntegrationTrue(
            !str_contains($stubCode, 'PrivateSupport'),
            'Library stub exported a #[NoExport] private helper: ' . $stub,
        );
        $providers[$target] = ['library' => $library, 'stub' => $stub];
    }

    // Import stubs automatically add both -l<target> options. Place both
    // linker-visible names in one directory so the consumer exercises a real
    // multi-library link rather than two independent executions.
    $linkRoot = $temporaryRoot . '/lib-link';
    if (!mkdir($linkRoot, 0777, true) && !is_dir($linkRoot)) {
        throw new IntegrationFailure('Cannot create library link directory: ' . $linkRoot);
    }
    foreach ($providers as $target => $provider) {
        $linkLibrary = $linkRoot . '/lib' . $target . '.' . PHP_SHLIB_SUFFIX;
        if (!copy($provider['library'], $linkLibrary)) {
            throw new IntegrationFailure('Cannot prepare linker-visible provider library: ' . $target);
        }
    }

    $consumerRoot = $temporaryRoot . '/consumer';
    copyIntegrationTree(TYPEPHP_INTEGRATION_TEST_ROOT . '/lib/consumer', $consumerRoot);
    foreach ($providers as $target => $provider) {
        if (!copy($provider['stub'], $consumerRoot . '/' . $target . '.stub.php')) {
            throw new IntegrationFailure('Cannot prepare provider import stub: ' . $target);
        }
    }
    $consumer = $temporaryRoot . '/integration_consumer';
    runIntegrationCommand([
        $options['compiler'], $consumerRoot,
        '--mode', 'bin', '--output', $consumer,
        '--build-dir', $consumerRoot . '/build',
        '--link-path', $linkRoot,
        '--job', getenv('PHP_COMPILER_JOBS') ?: '1', '--no-progress',
    ], environment: integrationCompilerEnvironment($options));
    $libraryPath = $linkRoot;
    $environment = PHP_OS_FAMILY === 'Darwin'
        ? ['DYLD_LIBRARY_PATH' => $libraryPath . ':' . (getenv('DYLD_LIBRARY_PATH') ?: '')]
        : ['LD_LIBRARY_PATH' => $libraryPath . ':' . (getenv('LD_LIBRARY_PATH') ?: '')];
    $result = runIntegrationCommand([$consumer], null, $environment);
    assertIntegrationSame(
        "42\ncounter=7\nscaled=21\nlabel=[peer]\n",
        $result['stdout'],
        'TypePHP multi-library consumer returned unexpected output',
    );
}

function removeIntegrationTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function main(array $argv): int
{
    $temporaryRoot = TYPEPHP_INTEGRATION_ROOT . '/build/integration-'
        . getmypid() . '-' . bin2hex(random_bytes(3));
    $succeeded = false;
    try {
        $options = parseIntegrationOptions($argv);
        if (!mkdir($temporaryRoot, 0777, true) && !is_dir($temporaryRoot)) {
            throw new IntegrationFailure('Cannot create integration build directory: ' . $temporaryRoot);
        }
        fwrite(STDOUT, 'Integration artifacts: ' . $temporaryRoot . PHP_EOL);
        if ($options['suite'] === 'all' || $options['suite'] === 'ext') {
            runExtIntegration($options, $temporaryRoot);
        }
        if ($options['suite'] === 'all' || $options['suite'] === 'lib') {
            runLibIntegration($options, $temporaryRoot);
        }
        if ($options['suite'] === 'fpm') {
            runFpmIntegration($options, $temporaryRoot);
        }
        $succeeded = true;
        fwrite(STDOUT, "\nIntegration tests passed\n");
        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, "\nFAIL: {$error->getMessage()}\n");
        fwrite(STDERR, "Artifacts retained at: {$temporaryRoot}\n");
        return 1;
    } finally {
        if ($succeeded && !in_array('--keep', $argv, true)) {
            removeIntegrationTree($temporaryRoot);
        }
    }
}

exit(main($argv));

<?php

use PHPUnit\Framework\TestCase;

final class PhptSanitizerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The compiler substitute uses an executable Unix PHP script');
        }
        $this->directory = sys_get_temp_dir() . '/typephp-phpt-sanitizer-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $compiler = <<<'PHP'
<?php
$output = str_replace('-', '_', basename($argv[1], '.php'));
for ($i = 2; $i < $argc; $i++) {
    if ($argv[$i] === '--output') $output = $argv[++$i];
}
file_put_contents(__DIR__ . '/commands.jsonl', json_encode(array_slice($argv, 2)) . "\n", FILE_APPEND | LOCK_EX);
file_put_contents(__DIR__ . '/compiler-env.jsonl', json_encode([
    'make' => getenv('MAKEFLAGS'), 'cmake' => getenv('CMAKE_BUILD_PARALLEL_LEVEL'),
    'cargo' => getenv('CARGO_BUILD_JOBS'), 'tpc' => getenv('PHP_COMPILER_JOBS'),
]) . "\n", FILE_APPEND | LOCK_EX);
$program = <<<'PROGRAM'
<?php
$options = getenv('ASAN_OPTIONS') ?: '';
if (getenv('EMIT_ASAN_REPORT') === 'stderr') {
    fwrite(STDERR, "==123==ASan runtime does not come first in initial library list\n");
    exit(1);
}
$mode = getenv('EMIT_ASAN_REPORT');
if (in_array($mode, ['1', 'warning', 'warning-error', 'unknown'], true) && preg_match('/log_path="([^"]+)"/', $options, $matches)) {
    $warning = "==123==WARNING: ASan is ignoring requested __asan_handle_no_return: stack type: default top: 0x1; bottom 0x2; size: 0x3\n"
        . "False positive error reports may follow\nFor details see https://github.com/google/sanitizers/issues/189\n";
    $diagnostic = match ($mode) {
        'warning' => $warning,
        'warning-error' => $warning . "ERROR: AddressSanitizer: heap-use-after-free\n",
        'unknown' => "Unknown sanitizer startup failure\n",
        default => "ERROR: AddressSanitizer: heap-buffer-overflow\n",
    };
    file_put_contents($matches[1] . '.' . getmypid(), $diagnostic);
}
$payload = json_encode(['allocator' => getenv('USE_ZEND_ALLOC'), 'options' => $options]);
file_put_contents(getenv('TYPEPHP_SANITIZER_EVIDENCE'), $payload . "\n", FILE_APPEND | LOCK_EX);
echo $payload;
PROGRAM;
file_put_contents($output, '#!' . PHP_BINARY . "\n" . $program);
chmod($output, 0700);
PHP;
        file_put_contents($this->directory . '/compiler.php', '#!' . PHP_BINARY . "\n" . $compiler);
        chmod($this->directory . '/compiler.php', 0700);
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) return;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testCompilerReceivesSanitizerAndRuntimeUsesSystemAllocator(): void
    {
        $this->fixture('clean');
        $result = $this->harness(['--sanitize', 'address', 'clean.phpt']);
        self::assertSame(0, $result['status'], $result['output']);
        $commands = $this->commands();
        self::assertSame(['--sanitize', 'undefined', '--sanitize', 'address'], array_slice($commands[0], 0, 4));
        self::assertNotContains('--debug', $commands[0]);
        self::assertSame(['--job', '1'], array_slice($commands[0], -2));
        self::assertStringContainsString('"allocator":"0"', file_get_contents($this->directory . '/runtime.jsonl'));
    }

    public function testReportCannotBeHiddenByExpectfOrXfail(): void
    {
        $this->fixture('poison', true);
        $result = $this->harness(['--sanitize', 'address', 'poison.phpt']);
        self::assertSame(1, $result['status'], $result['output']);
        self::assertStringContainsString('FAIL', $result['output']);
        self::assertStringNotContainsString('XFAIL ', $result['output']);
        self::assertNotEmpty(glob($this->directory . '/poison.log.sanitizer-*'));
        $output = file_get_contents($this->directory . '/poison.out');
        self::assertStringContainsString('heap-buffer-overflow', $output);
        self::assertStringContainsString('"allocator":"0"', $output);
        self::assertStringContainsString('halt_on_error=1', $output);
        self::assertStringContainsString('detect_leaks=0', $output);
    }

    public function testParallelWorkersKeepSanitizerAndSerialNestedCompiler(): void
    {
        $this->fixture('first');
        $this->fixture('second');
        $result = $this->harness(['--sanitize', 'address,undefined', '-j2', 'first.phpt', 'second.phpt']);
        self::assertSame(0, $result['status'], $result['output']);
        $commands = $this->commands();
        self::assertCount(2, $commands);
        foreach ($commands as $command) {
            self::assertContains('address,undefined', $command);
            self::assertSame(['--job', '1'], array_slice($command, -2));
        }
        foreach (file($this->directory . '/compiler-env.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
            self::assertSame(['make' => '-j1', 'cmake' => '1', 'cargo' => '1', 'tpc' => '1'], json_decode($line, true));
        }
    }

    public function testSanitizerStartupFailureCannotPassWithoutAReportFile(): void
    {
        $this->fixture('startup', true);
        $path = $this->directory . '/startup.phpt';
        file_put_contents($path, str_replace('EMIT_ASAN_REPORT=1', 'EMIT_ASAN_REPORT=stderr', file_get_contents($path)));
        $result = $this->harness(['--sanitize', 'address', 'startup.phpt']);
        self::assertSame(1, $result['status'], $result['output']);
        self::assertStringNotContainsString('XFAIL ', $result['output']);
        self::assertSame([], glob($this->directory . '/startup.log.sanitizer-*'));
    }

    /** @dataProvider diagnosticModes */
    public function testOnlyKnownFiberWarningsAreNonFatal(string $mode, int $expectedStatus): void
    {
        $this->fixture('diagnostic', $expectedStatus !== 0);
        $path = $this->directory . '/diagnostic.phpt';
        file_put_contents($path, preg_replace('/EMIT_ASAN_REPORT=\d/', 'EMIT_ASAN_REPORT=' . $mode, file_get_contents($path)));
        $result = $this->harness(['--sanitize', 'address', 'diagnostic.phpt']);
        self::assertSame($expectedStatus, $result['status'], $result['output']);
        self::assertNotEmpty(glob($this->directory . '/diagnostic.log.sanitizer-*'));
        if ($expectedStatus === 0) {
            self::assertStringContainsString('ASan Fiber warning retained', $result['output']);
        } else {
            self::assertStringNotContainsString('XFAIL ', $result['output']);
        }
    }

    public static function diagnosticModes(): array
    {
        return [
            'Fiber warning only' => ['warning', 0],
            'error after Fiber warning' => ['warning-error', 1],
            'unknown diagnostic' => ['unknown', 1],
        ];
    }

    /** @dataProvider invalidOptions */
    public function testRejectsInvalidOrInapplicableSanitizers(array $arguments): void
    {
        $result = $this->harness($arguments);
        self::assertNotSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('--sanitize', $result['output']);
        self::assertFileDoesNotExist($this->directory . '/commands.jsonl');
    }

    public static function invalidOptions(): array
    {
        return [
            'missing' => [['--sanitize']],
            'unknown' => [['--sanitize', 'thread']],
            'mixed unknown' => [['--sanitize', 'address,thread']],
            'plain PHP' => [['--sanitize', 'address', '--no-aot']],
            'WASM' => [['--sanitize', 'address', '--target', 'wasm-component']],
        ];
    }

    private function fixture(string $name, bool $poison = false): void
    {
        $content = "--TEST--\nSanitizer harness $name\n--AOT_ARGS--\n--sanitize undefined\n"
            . "--ENV--\nASAN_OPTIONS=halt_on_error=0:detect_leaks=1\nUSE_ZEND_ALLOC=1\n"
            . 'EMIT_ASAN_REPORT=' . ($poison ? '1' : '0') . "\n"
            . ($poison ? "--XFAIL--\nSanitizer reports must not be expected failures\n" : '')
            . "--FILE--\n<?php function main(): void {} ?>\n--EXPECTF--\n%a\n";
        file_put_contents($this->directory . '/' . $name . '.phpt', $content);
    }

    private function commands(): array
    {
        return array_map(static fn(string $line): array => json_decode($line, true),
            file($this->directory . '/commands.jsonl', FILE_IGNORE_NEW_LINES));
    }

    private function harness(array $arguments): array
    {
        $log = $this->directory . '/harness.log';
        $environment = getenv();
        $environment['NO_INTERACTION'] = '1';
        $environment['REPORT_EXIT_STATUS'] = '1';
        $environment['TYPEPHP_SANITIZER_EVIDENCE'] = $this->directory . '/runtime.jsonl';
        $environment['PHP_COMPILER_JOBS'] = '1';
        $environment['MAKEFLAGS'] = '-j8';
        $environment['CMAKE_BUILD_PARALLEL_LEVEL'] = '8';
        $environment['CARGO_BUILD_JOBS'] = '8';
        $process = proc_open([
            PHP_BINARY, TYPEPHP_ROOT_PATH . '/run-tests.php', '-q', '--no-color',
            '--compiler', $this->directory . '/compiler.php', ...$arguments,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]],
            $pipes, $this->directory, $environment);
        self::assertIsResource($process);
        $status = proc_close($process);
        return ['status' => $status, 'output' => file_get_contents($log)];
    }
}

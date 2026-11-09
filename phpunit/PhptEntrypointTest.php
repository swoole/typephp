<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhptEntrypointTest extends TestCase
{
    #[DataProvider('entrypoints')]
    public function testHarnessPreservesAndInvokesEntrypoint(string $source, bool $aot): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The compiler substitute uses an executable Unix PHP script');
        }

        $directory = sys_get_temp_dir() . '/typephp-phpt-entrypoint-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            // Execute the transformed source in place of a native binary. This
            // exposes an erroneous extra main() wrapper without a C++ build.
            $compiler = <<<'PHP'
<?php
$source = file_get_contents($argv[1]);
$output = str_replace('-', '_', basename($argv[1], '.php'));
$call = <<<'CALL'
<?php
if ((new ReflectionFunction('main'))->getNumberOfParameters() === 0) {
    main();
} else {
    main($argc, $argv);
}
CALL;
if (in_array(T_NAMESPACE, array_column(token_get_all($source), 0), true)) {
    $call = '<?php namespace { ' . substr($call, 5) . "\n}";
}
file_put_contents($output, '#!' . PHP_BINARY . "\n" . $source
    . (str_ends_with(trim($source), '?>') ? $call : substr($call, 5)));
chmod($output, 0700);
PHP;
            file_put_contents($directory . '/compiler.php', '#!' . PHP_BINARY . "\n" . $compiler);
            chmod($directory . '/compiler.php', 0700);
            file_put_contents($directory . '/entry.phpt',
                "--TEST--\nPHPT entrypoint\n--FILE--\n<?php\n" . $source . "\n?>\n--EXPECT--\nok\n");

            $environment = getenv();
            $environment['NO_INTERACTION'] = '1';
            $environment['REPORT_EXIT_STATUS'] = '1';
            $environment['PHP_COMPILER_JOBS'] = '1';
            $log = $directory . '/harness.log';
            $process = proc_open([
                PHP_BINARY, TYPEPHP_ROOT_PATH . '/run-tests.php', '-q', '--no-color', '-j1',
                ...($aot ? ['--compiler', $directory . '/compiler.php'] : ['--no-aot']),
                'entry.phpt',
            ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]],
                $pipes, $directory, $environment);
            self::assertIsResource($process);
            self::assertSame(0, proc_close($process), file_get_contents($log));
            self::assertStringContainsString('PASS', file_get_contents($log));
        } finally {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }

    public static function entrypoints(): iterable
    {
        $sources = [
            'no arguments' => 'function main(): void { echo func_num_args() === 0 ? "ok\n" : "wrong\n"; }',
            'argc and argv' => <<<'PHP'
function main(int $argc, array $argv): void {
    echo $argc === count($argv) && $argc >= 1 ? "ok\n" : "wrong\n";
}
PHP,
            'formatted declaration' => <<<'PHP'
function /* entrypoint */ main
(
    int $argc,
    array $argv
): void {
    echo $argc === count($argv) ? "ok\n" : "wrong\n";
}
PHP,
            'comment' => "// function main()\necho \"ok\\n\";",
            'string' => '$text = "function main()"; echo "ok\n";',
            'class method' => <<<'PHP'
class Example { public static function main(): void {} }
function main(int $argc, array $argv): void { echo "ok\n"; }
PHP,
            'global namespace' => <<<'PHP'
namespace {
    function main(): void { echo "ok\n"; }
}
PHP,
            'namespaced function before global entrypoint' => <<<'PHP'
namespace Example {
    function main(): void { echo "wrong\n"; }
}
namespace {
    function main(int $argc, array $argv): void { echo $argc === count($argv) ? "ok\n" : "wrong\n"; }
}
PHP,
        ];
        foreach ($sources as $name => $source) {
            yield $name . ' AOT' => [$source, true];
            yield $name . ' PHP' => [$source, false];
        }
    }
}

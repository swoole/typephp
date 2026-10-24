<?php

namespace TypePhp\Tests\Build;

use PHPUnit\Framework\TestCase;
use TypePhp\Build\PhpxLocator;

/** @coversNothing */
final class LibraryLifecycleTest extends TestCase
{
    public function testFailedLibraryStartupStopsTheConsumer(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The library startup failure integration test requires Linux');
        }
        $phpx = PhpxLocator::resolve(TYPEPHP_ROOT_PATH);
        if (!is_file($phpx . '/lib/libphpx.so')) {
            self::markTestSkipped('A built PHPX shared library is required');
        }
        $directory = sys_get_temp_dir() . '/typephp-library-failure-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $result = $this->execute([
                'c++', '-shared', '-fPIC', TYPEPHP_ROOT_PATH . '/phpunit/code/library/failing.cc',
                '-o', $directory . '/libfailing.so',
            ], $directory);
            self::assertSame(0, $result['status'], $result['output']);
            file_put_contents($directory . '/failing.stub.php',
                "<?php\n/** @import-library */\ninterface FailingLibraryMarker {}\n");
            file_put_contents($directory . '/main.php',
                "<?php\nfunction main(): void { echo 'unexpected-main'; }\n");
            $this->compile($directory, 'failure_consumer', 'bin', ['failing.stub.php', 'main.php']);
            $environment = getenv();
            $environment['LD_LIBRARY_PATH'] = $directory . ':' . ($environment['LD_LIBRARY_PATH'] ?? '');
            $result = $this->execute([$directory . '/failure_consumer'], $directory, $environment);
            self::assertNotSame(0, $result['status'], $result['output']);
            self::assertStringContainsString('startup-failed', $result['output']);
            self::assertStringNotContainsString('unexpected-main', $result['output']);
            self::assertStringNotContainsString('unexpected-shutdown', $result['output']);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testStandaloneCAbiLibraryRetainsItsEmbeddedRuntime(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The standalone C ABI integration test requires Linux');
        }
        $phpx = PhpxLocator::resolve(TYPEPHP_ROOT_PATH);
        if (!is_file($phpx . '/lib/libphpx.so')) {
            self::markTestSkipped('A built PHPX shared library is required');
        }
        $directory = sys_get_temp_dir() . '/typephp-cabi-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $result = $this->execute([
                PHP_BINARY, TYPEPHP_ROOT_PATH . '/bin/tpc.php',
                TYPEPHP_ROOT_PATH . '/examples/lib-demo/project.yml',
                '--output', $directory . '/demo.so', '--build-dir', $directory . '/build',
                '--job', getenv('PHP_COMPILER_JOBS') ?: '1', '--no-progress', '--no-color',
            ], $directory);
            self::assertSame(0, $result['status'], $result['output']);
            $result = $this->execute([
                'c++', '-std=c++17', TYPEPHP_ROOT_PATH . '/examples/lib-demo/smoke-test.cc',
                $directory . '/demo.so', '-Wl,-rpath,' . $directory, '-o', $directory . '/smoke',
            ], $directory);
            self::assertSame(0, $result['status'], $result['output']);
            $result = $this->execute([$directory . '/smoke'], $directory);
            self::assertSame(0, $result['status'], $result['output']);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testSharedLibrariesResetAcrossEmbeddedRequests(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The repeated embedded-runtime integration test requires Linux');
        }
        $phpx = PhpxLocator::resolve(TYPEPHP_ROOT_PATH);
        if (!is_file($phpx . '/lib/libphpx.so')) {
            self::markTestSkipped('A built PHPX shared library is required');
        }
        $directory = sys_get_temp_dir() . '/typephp-library-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $fixtures = TYPEPHP_ROOT_PATH . '/phpunit/code/library';

        try {
            foreach (['leaf', 'left', 'right'] as $library) {
                copy($fixtures . '/' . $library . '.php', $directory . '/' . $library . '.php');
                $sources = $library === 'leaf' ? [$library . '.php'] : ['leaf.stub.php', $library . '.php'];
                $this->compile($directory, $library, 'lib', $sources);
            }
            copy($fixtures . '/consumer.php', $directory . '/consumer.php');
            copy($fixtures . '/repeat.cc', $directory . '/repeat.cc');
            $this->compile(
                $directory,
                'consumer',
                'bin',
                ['leaf.stub.php', 'left.stub.php', 'right.stub.php', 'consumer.php', 'repeat.cc'],
                ['TYPEPHP_NO_MAIN=1'],
            );
            $environment = getenv();
            $environment['LD_LIBRARY_PATH'] = $directory . ':' . ($environment['LD_LIBRARY_PATH'] ?? '');
            $result = $this->execute([$directory . '/consumer'], $directory, $environment);

            self::assertSame(0, $result['status'], $result['output']);
            self::assertSame(
                str_repeat("overrides=1,1\nhook\nhook\nglobal=ready\nstatic=8\n", 3),
                $result['output'],
            );

            // A consumer loaded by PHP uses Zend's request hooks rather than
            // the standalone embedding entry point.
            $this->compile(
                $directory,
                'consumer',
                'ext',
                ['leaf.stub.php', 'left.stub.php', 'right.stub.php', 'consumer.php'],
            );
            $result = $this->execute(
                [PHP_BINARY, '-d', 'extension=' . $directory . '/consumer.so', '-r', 'exerciseLibrary();'],
                $directory,
                $environment,
            );
            self::assertSame(0, $result['status'], $result['output']);
            self::assertSame("overrides=1,1\nhook\nhook\nglobal=ready\nstatic=8\n", $result['output']);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }

    private function compile(string $directory, string $name, string $mode, array $sources, array $defines = []): void
    {
        $yaml = \Symfony\Component\Yaml\Yaml::dump([
            'name' => $name,
            'mode' => $mode,
            'build-dir' => 'build-' . $name,
            'sources' => $sources,
            'defines' => $defines,
            'link-paths' => [$directory],
        ]);
        file_put_contents($directory . '/' . $name . '.yml', $yaml);
        $result = $this->execute(
            [PHP_BINARY, TYPEPHP_ROOT_PATH . '/bin/tpc.php', $name . '.yml', '--job', getenv('PHP_COMPILER_JOBS') ?: '1', '--no-progress', '--no-color'],
            $directory,
        );
        self::assertSame(0, $result['status'], $result['output']);
    }

    /** @return array{status: int, output: string} */
    private function execute(array $command, string $directory, ?array $environment = null): array
    {
        // Merge stderr into stdout so a compiler filling either pipe cannot
        // block the parent while it waits for the other stream.
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ], $pipes, $directory, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return ['status' => proc_close($process), 'output' => $output];
    }
}

<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Tests\Build;

use PHPUnit\Framework\TestCase;
use TypePhp\Build\EmbeddedOpcodeGenerator;

/**
 * @internal
 * @coversNothing
 */
final class EmbeddedOpcodeGeneratorTest extends TestCase
{
    private string $directory;
    private string $buildDirectory;
    private string $vendorFile;
    private string $anonymousFile;
    private string $ordinaryFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/typephp-opcode-generator-' . bin2hex(random_bytes(6));
        $this->buildDirectory = $this->directory . '/build';
        $vendor = $this->directory . '/vendor';
        mkdir($vendor . '/package', 0777, true);
        file_put_contents($vendor . '/autoload.php', '<?php');
        $this->vendorFile = $vendor . '/package/library.php';
        $this->anonymousFile = $this->directory . '/anonymous.php';
        $this->ordinaryFile = $this->directory . '/ordinary.php';
        file_put_contents($this->vendorFile, '<?php return 1;');
        file_put_contents($this->anonymousFile, '<?php return new class {};');
        file_put_contents($this->ordinaryFile, '<?php return 2;');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testPrepareReusesOnlyVendorAndAnonymousCaches(): void
    {
        $files = [$this->vendorFile, $this->anonymousFile, $this->ordinaryFile];
        $generator = $this->generator();
        $first = $generator->prepare($files, [$this->anonymousFile => '@typephp:anon:test']);

        self::assertSame($files, $first->pendingFiles());
        self::assertTrue($first->isVendorFile($this->vendorFile));
        self::assertTrue($first->isAnonymousFile($this->anonymousFile));
        self::assertNotSame(
            $first->cacheDirectory($this->vendorFile),
            $first->cacheDirectory($this->anonymousFile),
        );

        $vendorBlob = $this->writeBlob($first->cacheDirectory($this->vendorFile), $this->vendorFile, 'vendor');
        $anonymousBlob = $this->writeBlob(
            $first->cacheDirectory($this->anonymousFile),
            $this->anonymousFile,
            'anonymous',
        );
        $ordinaryBlob = $this->writeBlob(
            $first->cacheDirectory($this->ordinaryFile),
            $this->ordinaryFile,
            'ordinary',
        );

        $second = $generator->prepare($files, [$this->anonymousFile => '@typephp:anon:test']);

        self::assertSame([
            $this->vendorFile => $vendorBlob,
            $this->anonymousFile => $anonymousBlob,
        ], $second->cachedBlobs());
        self::assertSame([$this->ordinaryFile], $second->pendingFiles());
        self::assertSame(1, $second->vendorHits());
        self::assertSame(1, $second->anonymousHits());
        self::assertFileDoesNotExist($ordinaryBlob);
        self::assertFileExists($this->buildDirectory . '/cache/opcache/compile-embedded-opcodes.php');
    }

    public function testForceClearsPersistentOpcodeCaches(): void
    {
        $files = [$this->vendorFile, $this->anonymousFile];
        $anonymousKeys = [$this->anonymousFile => '@typephp:anon:test'];
        $first = $this->generator()->prepare($files, $anonymousKeys);
        $this->writeBlob($first->cacheDirectory($this->vendorFile), $this->vendorFile, 'vendor');
        $this->writeBlob($first->cacheDirectory($this->anonymousFile), $this->anonymousFile, 'anonymous');

        $forced = $this->generator(true)->prepare($files, $anonymousKeys);

        self::assertSame([], $forced->cachedBlobs());
        self::assertSame($files, $forced->pendingFiles());
    }

    public function testVendorSkipMarkerAvoidsRepeatedCompilation(): void
    {
        $generator = $this->generator();
        $first = $generator->prepare([$this->vendorFile], []);
        $cacheDirectory = $first->cacheDirectory($this->vendorFile);
        mkdir($cacheDirectory, 0777, true);
        file_put_contents(
            $cacheDirectory . '/skipped-' . hash('sha256', $this->vendorFile) . '.txt',
            $this->vendorFile,
        );

        $second = $generator->prepare([$this->vendorFile], []);

        self::assertSame([], $second->pendingFiles());
        self::assertSame(1, $second->skippedVendorCount());
        self::assertSame(0, $second->vendorHits());
    }

    public function testLegacyOpcodeCachesAreNotReused(): void
    {
        $vendor = dirname($this->vendorFile, 2);
        $vendorKey = hash('sha256', implode("\n", [
            'vendor-directory-mtime-opcodes-v2', $vendor, (string) filemtime($vendor), 'build-signature',
        ]));
        $anonymousKey = hash('sha256', implode("\n", [
            'anonymous-opcodes-v1', $this->anonymousFile, hash_file('sha256', $this->anonymousFile), 'build-signature',
        ]));
        $this->writeBlob($this->buildDirectory . '/cache/opcache/vendor-' . substr($vendorKey, 0, 20), $this->vendorFile, 'unsafe');
        $this->writeBlob($this->buildDirectory . '/cache/opcache/anonymous-' . substr($anonymousKey, 0, 20), $this->anonymousFile, 'unsafe');

        $batch = $this->generator()->prepare(
            [$this->vendorFile, $this->anonymousFile],
            [$this->anonymousFile => '@typephp:anon:test'],
        );

        self::assertSame([], $batch->cachedBlobs());
        self::assertSame([$this->vendorFile, $this->anonymousFile], $batch->pendingFiles());
    }

    public function testBuildProcessUsesAnExplicitEmptyIniScanDirectory(): void
    {
        $generator = $this->generator();
        $generator->prepare([], []);
        $ini = $this->directory . '/build.ini';
        file_put_contents($ini, '');
        // Inspect the real child environment without compiling opcodes or
        // disabling the functions used by this diagnostic driver.
        (new \ReflectionProperty($generator, 'compileIniPath'))->setValue($generator, $ini);
        file_put_contents($this->buildDirectory . '/cache/opcache/compile-embedded-opcodes.php', <<<'PHP'
<?php
echo json_encode([
    'scan_directory' => getenv('PHP_INI_SCAN_DIR'),
    'scanned_files' => php_ini_scanned_files(),
]);
PHP);
        $inheritedScanDirectory = $this->directory . '/inherited-ini';
        mkdir($inheritedScanDirectory);
        file_put_contents($inheritedScanDirectory . '/unwanted.ini', 'precision=7');
        $previousScanDirectory = getenv('PHP_INI_SCAN_DIR');
        try {
            putenv('PHP_INI_SCAN_DIR=' . $inheritedScanDirectory);
            [$status, $stdout, $stderr] = (new \ReflectionMethod($generator, 'compileFile'))->invoke(
                $generator, $this->ordinaryFile, $this->buildDirectory . '/cache/opcache',
            );
        } finally {
            putenv($previousScanDirectory === false
                ? 'PHP_INI_SCAN_DIR'
                : 'PHP_INI_SCAN_DIR=' . $previousScanDirectory);
        }

        self::assertSame(0, $status, $stderr);
        $configuration = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($configuration['scan_directory']);
        self::assertNotSame('', $configuration['scan_directory']);
        self::assertNotSame($inheritedScanDirectory, $configuration['scan_directory']);
        self::assertDirectoryExists($configuration['scan_directory']);
        self::assertSame([], array_values(array_diff(scandir($configuration['scan_directory']), ['.', '..'])));
        self::assertFalse($configuration['scanned_files']);
        self::assertSame('', $stderr);
    }

    public function testEmbeddedBuiltinsResolveByNameWithoutFramelessIndices(): void
    {
        $this->verifyBuiltinCalls(false);
    }

    public function testEmbeddedBuiltinsSurviveADifferentFramelessTable(): void
    {
        $this->verifyBuiltinCalls(true);
    }

    public function testFramelessLayoutProbeRejectsACacheMiss(): void
    {
        $this->verifyBuiltinCalls(true, true);
    }

    public function testFramelessLayoutProbeReplaysADifferentSystemId(): void
    {
        $this->verifyBuiltinCalls(true, false, true);
    }

    private function verifyBuiltinCalls(bool $differentLayout, bool $cacheMiss = false, bool $differentSystemId = false): void
    {
        $extensionArguments = $this->opcacheArguments();
        $ffiArguments = [];
        if ($differentLayout) {
            $ffiArguments = $this->ffiArguments();
        }
        file_put_contents($this->anonymousFile, <<<'PHP'
<?php
function embeddedBuiltinCalls(string $value): array {
    return [strtolower($value), ltrim($value, 'A'), str_ends_with($value, 'Z'),
        array_diff_key(['keep' => 1, 'drop' => 2], ['drop' => 0]), str_contains($value, 'b')];
}
return 'cached';
PHP);
        touch($this->anonymousFile, time() - 10);
        $generator = $this->generator(false, [...$extensionArguments, '-d', 'opcache.opt_debug_level=131072']);
        $batch = $generator->prepare([$this->anonymousFile], [$this->anonymousFile => '@typephp:anon:test']);
        mkdir($batch->cacheDirectory($this->anonymousFile), 0777, true);
        [$status, $version, $dump] = (new \ReflectionMethod($generator, 'compileFile'))->invoke(
            $generator, $this->anonymousFile, $batch->cacheDirectory($this->anonymousFile),
        );

        self::assertSame(0, $status, $dump);
        self::assertSame(PHP_VERSION, strtok($version, "\r\n"));
        self::assertStringContainsString('strtolower', $dump);
        self::assertStringNotContainsString('FRAMELESS_ICALL', $dump);
        self::assertStringNotContainsString('JMP_FRAMELESS', $dump);
        $blobs = $generator->compile($batch);
        self::assertArrayHasKey($this->anonymousFile, $blobs);
        if ($differentSystemId) {
            $original = $blobs[$this->anonymousFile];
            $bytes = file_get_contents($original);
            $systemId = substr($bytes, 8, 32);
            $otherId = str_repeat('0', 32);
            $other = str_replace(
                '/' . $systemId . '/', '/' . $otherId . '/', str_replace('\\', '/', $original), $replacements,
            );
            self::assertSame(1, $replacements);
            mkdir(dirname($other), 0777, true);
            file_put_contents($other, substr_replace($bytes, $otherId, 8, 32));
            self::assertTrue(unlink($original));
            $blobs[$this->anonymousFile] = $other;
        }
        if ($differentLayout) {
            $mtime = filemtime($this->anonymousFile);
            $length = filesize($this->anonymousFile);
            // Preserve the size and timestamp but change the return marker.
            // A cache miss is now distinguishable from a cached execution.
            file_put_contents($this->anonymousFile, str_pad("<?php return 'source';", $length));
            touch($this->anonymousFile, $mtime + ($cacheMiss ? 1 : 0));
        }

        // Load the produced cache in a fresh process with all normal builtins
        // enabled. The build-only disabled-functions policy must not leak into
        // the cached program's execution.
        $execute = $differentLayout
            ? [dirname(__DIR__, 2) . '/code/embedded-opcode-frameless-layout.php', $this->anonymousFile, $blobs[$this->anonymousFile]]
            : ['-r', 'require $argv[1]; echo json_encode(embeddedBuiltinCalls("AbZ"));', $this->anonymousFile];
        [$status, $stdout, $stderr] = $this->runPhp([
            PHP_BINARY, '-n', ...$extensionArguments, ...$ffiArguments,
            '-d', 'opcache.enable_cli=1', '-d', 'opcache.file_cache_only=1',
            '-d', 'opcache.file_update_protection=0',
            '-d', 'opcache.validate_timestamps=1',
            '-d', 'opcache.jit_buffer_size=0',
            '-d', 'opcache.file_cache=' . $batch->cacheDirectory($this->anonymousFile),
            ...$execute,
        ]);
        if ($cacheMiss) {
            self::assertNotSame(0, $status);
            self::assertStringContainsString('OPcache did not replay the generated cache', $stdout . $stderr);
            return;
        }
        self::assertSame(0, $status, $stderr);
        self::assertSame('["abz","bZ",true,{"keep":1},true]', $stdout);
    }

    /** @return list<string> */
    private function ffiArguments(): array
    {
        $extension = ini_get('extension_dir') . DIRECTORY_SEPARATOR
            . (PHP_OS_FAMILY === 'Windows' ? 'php_ffi.dll' : 'ffi.' . PHP_SHLIB_SUFFIX);
        foreach ([['-d', 'ffi.enable=1'], ['-d', 'ffi.enable=1', '-d', 'extension=' . $extension]] as $arguments) {
            [$status] = $this->runPhp([
                PHP_BINARY, '-n', ...$arguments, '-r', 'exit(class_exists("FFI") ? 0 : 1);',
            ]);
            if ($status === 0) {
                return $arguments;
            }
        }
        self::markTestSkipped('FFI is needed only to simulate a different frameless table in this test');
    }

    /** @return list<string> */
    private function opcacheArguments(): array
    {
        $extension = ini_get('extension_dir') . DIRECTORY_SEPARATOR
            . (PHP_OS_FAMILY === 'Windows' ? 'php_opcache.dll' : 'opcache.' . PHP_SHLIB_SUFFIX);
        foreach ([[], ['-d', 'zend_extension=' . $extension]] as $arguments) {
            [$status] = $this->runPhp([
                PHP_BINARY, '-n', ...$arguments, '-r', 'exit(function_exists("opcache_compile_file") ? 0 : 1);',
            ]);
            if ($status === 0) {
                return $arguments;
            }
        }
        self::markTestSkipped('The build PHP CLI needs OPcache for the file-cache integration test');
    }

    /** @param list<string> $command
     * @return array{int, string, string}
     */
    private function runPhp(array $command): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    /** @param list<string> $extensionArguments */
    private function generator(bool $force = false, array $extensionArguments = []): EmbeddedOpcodeGenerator
    {
        return new EmbeddedOpcodeGenerator(
            $this->buildDirectory,
            'app',
            PHP_BINARY,
            $extensionArguments,
            PHP_VERSION,
            'build-signature',
            $force,
        );
    }

    private function writeBlob(string $cacheDirectory, string $source, string $contents): string
    {
        $blob = $cacheDirectory . '/system' . $source . '.bin';
        mkdir(dirname($blob), 0777, true);
        file_put_contents($blob, $contents);
        return $blob;
    }
}

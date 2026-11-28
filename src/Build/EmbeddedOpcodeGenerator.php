<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

final class EmbeddedOpcodeGenerator
{
    private ?string $compileIniPath = null;

    private const string DRIVER_SOURCE = <<<'PHP'
<?php
echo PHP_VERSION, "\n";
foreach ($argv as $index => $path) {
    if ($index === 0) {
        continue;
    }
    if (!opcache_compile_file($path)) {
        throw new RuntimeException("OPcache could not compile: {$path}");
    }
}
PHP;

    /** @param list<string> $extensionArguments */
    public function __construct(
        private readonly string $buildDirectory,
        private readonly string $targetName,
        private readonly string $php,
        private readonly array $extensionArguments,
        private readonly string $phpVersion,
        private readonly string $buildSignature,
        private readonly bool $force,
    ) {
    }

    /**
     * Resolve persistent cache hits and produce an immutable compilation batch.
     *
     * @param list<string> $files
     * @param array<string, string> $anonymousKeys
     */
    public function prepare(array $files, array $anonymousKeys): EmbeddedOpcodeBatch
    {
        $cacheDirectories = [];
        $vendorGroups = [];
        $vendorFiles = [];
        $anonymousFiles = [];
        $otherFiles = [];

        foreach ($files as $file) {
            if (isset($anonymousKeys[$file])) {
                $cacheDirectory = $this->anonymousCacheDirectory($file);
                if ($this->force) {
                    $this->clearDirectory($cacheDirectory);
                }
                $cacheDirectories[$file] = $cacheDirectory;
                $anonymousFiles[$file] = true;
                continue;
            }

            $vendorRoot = $this->vendorRoot($file);
            if ($vendorRoot === null) {
                $otherFiles[] = $file;
                continue;
            }
            $vendorGroups[$vendorRoot][] = $file;
            $vendorFiles[$file] = true;
        }

        foreach ($vendorGroups as $vendorRoot => $vendorGroup) {
            $cacheDirectory = $this->vendorCacheDirectory($vendorRoot);
            if ($this->force) {
                $this->clearDirectory($cacheDirectory);
            }
            foreach ($vendorGroup as $file) {
                $cacheDirectories[$file] = $cacheDirectory;
            }
        }

        if ($otherFiles !== []) {
            // Ordinary directories have no reliable invalidation boundary.
            // Recreate their shared staging directory for every build.
            $cacheDirectory = $this->buildDirectory . '/cache/opcache/nonvendor-'
                . substr(hash('sha256', $this->targetName), 0, 20);
            $this->clearDirectory($cacheDirectory);
            foreach ($otherFiles as $file) {
                $cacheDirectories[$file] = $cacheDirectory;
            }
        }

        $this->writeDriver();
        return $this->resolve($files, $cacheDirectories, $vendorFiles, $anonymousFiles);
    }

    /**
     * Compile only the cache misses from a prepared batch.
     *
     * @param callable(string): void|null $onSkipped
     * @param callable(int, int, string): void|null $onProgress
     * @return array<string, string> source file => OPcache blob
     */
    public function compile(
        EmbeddedOpcodeBatch $batch,
        ?callable $onSkipped = null,
        ?callable $onProgress = null,
    ): array {
        $blobs = $batch->cachedBlobs();
        $total = $batch->pendingCount();
        foreach ($batch->pendingFiles() as $index => $file) {
            $cacheDirectory = $batch->cacheDirectory($file);
            $this->ensureDirectory($cacheDirectory);
            [$status, $stdout, $stderr] = $this->compileFile($file, $cacheDirectory);

            if ($status !== 0) {
                if ($batch->isAnonymousFile($file)
                    || !str_contains($stderr, "OPcache could not compile: {$file}")) {
                    throw new \RuntimeException("Cannot compile embedded opcode {$file}:\n{$stderr}");
                }
                if ($batch->isVendorFile($file)) {
                    AtomicFile::write($this->skippedMarker($cacheDirectory, $file), $file . PHP_EOL, '.skip-');
                }
                if ($onSkipped !== null) {
                    $onSkipped($file);
                }
            } else {
                // Compiled files may emit PHP deprecation notices after the
                // version line printed by the driver.
                if (strtok($stdout, "\r\n") !== $this->phpVersion) {
                    throw new \RuntimeException("Build PHP version changed while compiling {$file}");
                }
                $blob = $this->findBlob($cacheDirectory, $file);
                if ($blob === null) {
                    throw new \RuntimeException("Missing OPcache blob for {$file}");
                }
                $blobs[$file] = $blob;
            }

            if ($onProgress !== null) {
                $onProgress($index + 1, $total, $file);
            }
        }

        uksort($blobs, static fn (string $left, string $right): int => strcmp($left, $right));
        return $blobs;
    }

    /**
     * @param list<string> $files
     * @param array<string, string> $cacheDirectories
     * @param array<string, true> $vendorFiles
     * @param array<string, true> $anonymousFiles
     */
    private function resolve(
        array $files,
        array $cacheDirectories,
        array $vendorFiles,
        array $anonymousFiles,
    ): EmbeddedOpcodeBatch {
        $cachedBlobs = [];
        $pendingFiles = [];
        $skippedVendorFiles = [];
        foreach ($files as $file) {
            $cacheDirectory = $cacheDirectories[$file];
            if (isset($vendorFiles[$file])
                && is_file($this->skippedMarker($cacheDirectory, $file))) {
                $skippedVendorFiles[$file] = true;
                continue;
            }
            if ((isset($vendorFiles[$file]) || isset($anonymousFiles[$file]))
                && ($blob = $this->findBlob($cacheDirectory, $file)) !== null) {
                $cachedBlobs[$file] = $blob;
                continue;
            }
            $pendingFiles[] = $file;
        }

        return new EmbeddedOpcodeBatch(
            $cachedBlobs,
            $pendingFiles,
            $cacheDirectories,
            $vendorFiles,
            $anonymousFiles,
            $skippedVendorFiles,
        );
    }

    private function anonymousCacheDirectory(string $file): string
    {
        $fileHash = hash_file('sha256', $file);
        if (!is_string($fileHash)) {
            throw new \RuntimeException("Cannot hash anonymous class source: {$file}");
        }
        $key = hash('sha256', implode("\n", [
            'anonymous-opcodes-v2-portable-calls',
            $file,
            $fileHash,
            $this->buildSignature,
        ]));
        return $this->buildDirectory . '/cache/opcache/anonymous-' . substr($key, 0, 20);
    }

    private function vendorCacheDirectory(string $vendorRoot): string
    {
        clearstatcache(true, $vendorRoot);
        $mtime = filemtime($vendorRoot);
        if ($mtime === false) {
            throw new \RuntimeException("Cannot read vendor directory mtime: {$vendorRoot}");
        }
        $key = hash('sha256', implode("\n", [
            'vendor-directory-mtime-opcodes-v3-portable-calls',
            $vendorRoot,
            (string) $mtime,
            $this->buildSignature,
        ]));
        return $this->buildDirectory . '/cache/opcache/vendor-' . substr($key, 0, 20);
    }

    /** Only files under a vendor directory with autoload.php use the persistent cache. */
    private function vendorRoot(string $file): ?string
    {
        for ($directory = dirname($file); $directory !== dirname($directory); $directory = dirname($directory)) {
            if (basename($directory) === 'vendor' && is_file($directory . '/autoload.php')) {
                return $directory;
            }
        }
        return null;
    }

    /** @return array{int, string, string} */
    private function compileFile(string $file, string $cacheDirectory): array
    {
        $command = [
            $this->php,
            '-c',
            $this->compileConfiguration(),
            ...$this->extensionArguments,
            '-d',
            'opcache.enable_cli=1',
            '-d',
            'opcache.file_cache_only=1',
            '-d',
            'opcache.file_update_protection=0',
            '-d',
            'opcache.file_cache=' . $cacheDirectory,
            $this->driverPath(),
            $file,
        ];
        // proc_open() drops empty environment values, which would restore
        // PHP's default INI scan directory instead of disabling it.
        $scanDirectory = $this->buildDirectory . '/cache/opcache/php-ini-scan-empty';
        $this->ensureDirectory($scanDirectory);
        $environment = getenv();
        $environment['PHP_INI_SCAN_DIR'] = $scanDirectory;
        return $this->runPhp($command, $environment);
    }

    /**
     * Frameless ICALL operands contain indices into a process-local
     * frameless-handler table. That table can differ between CLI and embed
     * even for the same PHP version. Hide internal functions while compiling
     * embedded files so calls retain their names and resolve in the target VM.
     * The target runtime's functions and the AOT C++ paths are unaffected.
     */
    private function compileConfiguration(): string
    {
        if ($this->compileIniPath !== null) {
            return $this->compileIniPath;
        }
        [$status, $stdout, $stderr] = $this->runPhp([
            $this->php, '-n', ...$this->extensionArguments,
            '-r', 'echo json_encode(get_defined_functions()["internal"]);',
        ]);
        $functions = json_decode($stdout, true);
        if ($status !== 0 || !is_array($functions)) {
            throw new \RuntimeException("Cannot query embedded opcode build functions:\n{$stderr}");
        }
        $disabled = [];
        foreach ($functions as $function) {
            if (!is_string($function)) {
                throw new \RuntimeException('Invalid embedded opcode build function name');
            }
            // The serializer has no frameless entry. PHP exposes exit/die
            // (8.4+) and clone (8.5+) as functions but forbids disabling them.
            if (!in_array($function, ['opcache_compile_file', 'exit', 'die', 'clone'], true)) {
                $disabled[] = $function;
            }
        }
        // Use an isolated INI rather than a long -d argument: a full function
        // list can exceed the Windows command-line length limit. Suppressing
        // the scan directory keeps the process as isolated as the old -n path.
        // Each generator owns its INI: replacing a shared file can leave a
        // gap on Windows and let another PHP process use its default config.
        $path = $this->buildDirectory . '/cache/opcache/portable-build-'
            . bin2hex(random_bytes(12)) . '.ini';
        AtomicFile::write($path, 'disable_functions=' . implode(',', $disabled) . PHP_EOL, '.ini-');
        return $this->compileIniPath = $path;
    }

    /** @param list<string> $command
     * @param array<string, string>|null $environment
     * @return array{int, string, string}
     */
    private function runPhp(array $command, ?array $environment = null): array
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start the PHP OPcache build process');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    private function writeDriver(): void
    {
        $path = $this->driverPath();
        if (is_file($path) && file_get_contents($path) === self::DRIVER_SOURCE) {
            return;
        }
        AtomicFile::write($path, self::DRIVER_SOURCE, '.driver-');
    }

    private function driverPath(): string
    {
        return $this->buildDirectory . '/cache/opcache/compile-embedded-opcodes.php';
    }

    private function skippedMarker(string $cacheDirectory, string $file): string
    {
        return $cacheDirectory . '/skipped-' . hash('sha256', $file) . '.txt';
    }

    private function findBlob(string $cacheDirectory, string $file): ?string
    {
        $matches = [];
        foreach (glob($cacheDirectory . '/*', GLOB_ONLYDIR) ?: [] as $systemDirectory) {
            $candidate = $systemDirectory . $file . '.bin';
            if (is_file($candidate) && filesize($candidate) > 0) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) > 1) {
            throw new \RuntimeException("Ambiguous OPcache blob for {$file}");
        }
        if ($matches !== []) {
            return $matches[0];
        }
        if (DIRECTORY_SEPARATOR !== '\\' || !is_dir($cacheDirectory)) {
            return null;
        }

        // OPcache omits the drive colon from Windows cache paths (C:\foo
        // becomes C\foo) after two system-specific cache directories.
        $cacheSuffix = str_replace('/', '\\', preg_replace('/^([A-Za-z]):/', '$1', $file)) . '.bin';
        foreach (glob($cacheDirectory . '/*', GLOB_ONLYDIR) ?: [] as $systemDirectory) {
            foreach (glob($systemDirectory . '/*', GLOB_ONLYDIR) ?: [] as $subDirectory) {
                $candidate = $subDirectory . DIRECTORY_SEPARATOR . $cacheSuffix;
                if (is_file($candidate) && filesize($candidate) > 0) {
                    $matches[] = $candidate;
                }
            }
        }
        if (count($matches) > 1) {
            throw new \RuntimeException("Ambiguous OPcache blob for {$file}");
        }
        if ($matches !== []) {
            return $matches[0];
        }

        // Retain compatibility with PHP builds that use another number of
        // system-specific directories.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDirectory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $cached) {
            if ($cached->isFile() && $cached->getSize() > 0
                && str_ends_with($cached->getPathname(), $cacheSuffix)) {
                if ($matches !== []) {
                    throw new \RuntimeException("Ambiguous OPcache blob for {$file}");
                }
                $matches[] = $cached->getPathname();
            }
        }
        return $matches[0] ?? null;
    }

    private function clearDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir() ? !rmdir($path) : !unlink($path)) {
                throw new \RuntimeException("Cannot clear opcode build directory: {$path}");
            }
        }
        if (!rmdir($directory)) {
            throw new \RuntimeException("Cannot clear opcode build directory: {$directory}");
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create OPcache build directory: {$directory}");
        }
    }
}

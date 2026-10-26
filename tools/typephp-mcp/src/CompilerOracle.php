<?php

/**
 * L3 compiler oracle: run `tpc --dry` on a file.
 *
 * The compiler is a compiled tpc binary when one is available
 * (TYPEPHP_MCP_TPC, or tpc/tpc.exe in the repository root), otherwise the
 * interpreted `php bin/tpc.php`. The compiled form is preferred because it
 * needs neither a PHP interpreter nor the phpy extension.
 *
 * --dry only generates C++, so it validates the TypePHP subset (the #1 AI
 * failure mode) without compiling or linking. The result names the stage that
 * actually ran (generate) so callers do not mistake it for a compile/link
 * check; compile and link are separate stages this tool does not run.
 *
 * Lifetime controls: the compiler runs as a controlled child process with a
 * timeout, an output budget and (on POSIX) a virtual-memory cap covering the
 * whole process tree. On timeout the tree is killed, the temporary build
 * directory is removed, and the call fails with a tool error so the MCP
 * server stays responsive instead of blocking on a stuck compiler.
 *
 * Tool-execution failures (missing file, missing PHP, compiler could not
 * start or did not finish) raise CompilerOracle::ToolFailure; the MCP layer
 * maps that to isError: true. User code that fails the check is NOT a tool
 * failure - it returns a normal structured result with diagnostics.
 *
 * Note: tpc still detects the host toolchain at startup (it fatals when no
 * C/C++ compiler is on PATH), so check_code makes sure an MSVC cl is
 * reachable before spawning the compiler.
 */
/**
 * A tool-execution failure: the check could not run at all (missing input,
 * missing toolchain, compiler timeout). The MCP layer maps this to
 * isError: true, as opposed to a normal result reporting that user code
 * failed the check.
 */
final class CompilerOracleToolFailure extends RuntimeException
{
}

final class CompilerOracle
{
    public const STAGE_GENERATE = 'generate';

    private const DEFAULT_TIMEOUT_SECONDS = 120;

    private const DEFAULT_OUTPUT_LIMIT_BYTES = 65536;

    private const DEFAULT_MEMORY_LIMIT_MB = 4096;

    private const PROBE_TIMEOUT_SECONDS = 10;

    /**
     * Cached compiler command prefix, resolved once per process.
     *
     * @var string|null
     */
    private static $compilerPrefix = null;

    /**
     * @return array{code: int, output: string, timedOut: bool}
     */
    public static function check(string $file, RepoRoot $root): string
    {
        $resolved = $file;
        if (!is_file($resolved)) {
            $inside = $root->resolveInside($file);
            $resolved = is_string($inside) ? $inside : '';
        }
        if ($resolved === '' || !is_file($resolved)) {
            throw new CompilerOracleToolFailure(
                'File not found: ' . $file . "\nPass an absolute path, or a path relative to the repo root."
            );
        }

        // Resolving the compiler also validates that one is reachable at all
        // (a compiled tpc, or PHP able to run bin/tpc.php).
        self::compilerPrefix($root);

        self::ensureHostToolchain();

        $buildDir = self::buildDir();
        if (!mkdir($buildDir, 0777, true)) {
            // sys_get_temp_dir() can resolve to an unusable location when a
            // client strips TMP/TEMP; retry under the OS temp constant.
            $fallback = rtrim(self::fallbackTempDir(), '/\\') . '/typephp-mcp-dry-' . uniqid();
            if (!mkdir($fallback, 0777, true)) {
                throw new CompilerOracleToolFailure('Cannot create build directory: ' . $fallback);
            }
            $buildDir = $fallback;
        }

        try {
            $result = self::runTpc($root, [
                '--dry',
                $resolved,
                '--build-dir',
                $buildDir,
                '--no-color',
                '--no-progress',
            ], self::DEFAULT_TIMEOUT_SECONDS);
            return self::format($resolved, $root, $result);
        } finally {
            self::removeDir($buildDir);
        }
    }

    /**
     * Run `php bin/tpc.php <args>` with a bounded lifetime and return the
     * merged output plus exit code. Throws ToolFailure when the compiler
     * could not be started or did not finish within the timeout.
     *
     * @param array<int, string> $args
     * @return array{code: int, output: string, timedOut: bool}
     */
    public static function runTpc(RepoRoot $root, array $args, ?int $timeoutSeconds = null): array
    {
        return self::runProcess(
            self::buildCommand($root, $args),
            self::timeoutSeconds($timeoutSeconds),
            self::outputLimit()
        );
    }

    /**
     * tpc --version probe used by get_index_info. Bounded like the check
     * itself so a stuck PHP cannot hang the server.
     */
    public static function tpcVersion(RepoRoot $root): string
    {
        try {
            $result = self::runTpc($root, ['--version', '--no-color'], self::PROBE_TIMEOUT_SECONDS);
        } catch (CompilerOracleToolFailure $error) {
            return 'unknown';
        }
        foreach (explode("\n", $result['output']) as $line) {
            if (preg_match('/v\d+\.\d+\.\d+/', $line, $m) === 1) {
                return $m[0];
            }
        }
        return 'unknown';
    }

    /**
     * Assemble the command line. On POSIX the compiler is wrapped in a shell
     * that caps virtual memory for the whole process tree before exec; tpc
     * --dry only generates C++ (no make/link), but the translation pipeline
     * still runs in PHP and must not be able to exhaust the machine. A real
     * compile/link tier would additionally need the shared build lock and
     * serial Make/CMake jobs (see AGENTS.md) - this tool intentionally does
     * not provide such a tier.
     */
    private static function buildCommand(RepoRoot $root, array $args): string
    {
        $parts = [self::compilerPrefix($root)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }
        $command = implode(' ', $parts);
        if (stripos(PHP_OS, 'WIN') !== 0) {
            $limitMb = self::memoryLimitMb();
            $command = 'bash -c ' . escapeshellarg(
                'ulimit -v ' . ($limitMb * 1024) . '; exec ' . $command
            );
        }
        return $command;
    }

    /**
     * Command prefix that runs the compiler.
     *
     * A compiled tpc binary is preferred when one is available (TYPEPHP_MCP_TPC,
     * or tpc/tpc.exe in the repository root): it needs neither a PHP
     * interpreter nor the phpy extension. That matters on Windows, where the
     * release package ships tpc.exe while the interpreted `php bin/tpc.php`
     * only works with phpy loaded. Falls back to the interpreted compiler.
     */
    private static function compilerPrefix(RepoRoot $root): string
    {
        if (self::$compilerPrefix !== null) {
            return self::$compilerPrefix;
        }
        self::$compilerPrefix = self::resolveCompilerPrefix($root);
        return self::$compilerPrefix;
    }

    private static function resolveCompilerPrefix(RepoRoot $root): string
    {
        $override = getenv('TYPEPHP_MCP_TPC');
        if (is_string($override) && $override !== '' && is_file($override)) {
            return escapeshellarg($override);
        }
        // Prefer a compiled tpc, but only when it actually runs: a binary that
        // cannot start (missing runtime DLLs, missing mbstring) must not be
        // preferred over a working interpreted compiler. One bounded --version
        // probe per process decides it.
        foreach (self::tpcBinaryNames() as $name) {
            $candidate = $root->path() . '/' . $name;
            if (!is_file($candidate)) {
                continue;
            }
            try {
                $probe = self::runProcess(
                    escapeshellarg($candidate) . ' --version --no-color',
                    self::PROBE_TIMEOUT_SECONDS,
                    8192
                );
            } catch (CompilerOracleToolFailure $error) {
                continue;
            }
            if ($probe['code'] === 0) {
                return escapeshellarg($candidate);
            }
        }
        $php = self::phpBinary();
        if ($php === '') {
            throw new CompilerOracleToolFailure(
                "No TypePHP compiler found: no runnable tpc/tpc.exe in the repository root and no PHP on PATH.\n"
                . 'check_code needs `tpc --dry`, or `php bin/tpc.php --dry` (which needs the phpy extension).'
                . "\nSet TYPEPHP_MCP_TPC to a tpc binary, or TYPEPHP_MCP_PHP to a php executable."
            );
        }
        return escapeshellarg($php) . ' ' . escapeshellarg($root->path() . '/bin/tpc.php');
    }

    /**
     * @return array<int, string>
     */
    private static function tpcBinaryNames(): array
    {
        return stripos(PHP_OS, 'WIN') === 0 ? ['tpc.exe', 'tpc'] : ['tpc', 'tpc.exe'];
    }

    /**
     * Whether any compiler invocation is possible (a compiled tpc, or PHP able
     * to run bin/tpc.php). Lets callers skip cleanly instead of failing.
     */
    public static function hasCompiler(RepoRoot $root): bool
    {
        try {
            self::compilerPrefix($root);
        } catch (CompilerOracleToolFailure $error) {
            return false;
        }
        return true;
    }

    /**
     * Execute a command with timeout, output budget and tree kill.
     *
     * The child's stdout/stderr are drained continuously (stored output is
     * capped, reading is not, so the child can never block on a full pipe),
     * and the process is reaped before returning.
     *
     * @return array{code: int, output: string, timedOut: bool}
     */
    private static function runProcess(string $command, int $timeoutSeconds, int $outputLimitBytes): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new CompilerOracleToolFailure('Cannot start the TypePHP compiler process.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $timedOut = false;
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) ceil($remaining), (int) round(($remaining - floor($remaining)) * 1000000));
            if ($ready === false) {
                break;
            }
            if ($ready > 0) {
                foreach ($read as $stream) {
                    $chunk = (string) @fread($stream, 65536);
                    if ($chunk === '') {
                        continue;
                    }
                    $room = $outputLimitBytes - strlen($output);
                    if ($room > 0) {
                        $output .= $room >= strlen($chunk) ? $chunk : substr($chunk, 0, $room);
                    }
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                // Final drain: the child may have written between the select
                // and the status check.
                foreach ([$pipes[1], $pipes[2]] as $stream) {
                    $chunk = (string) @fread($stream, 65536);
                    if ($chunk !== '') {
                        $room = $outputLimitBytes - strlen($output);
                        if ($room > 0) {
                            $output .= $room >= strlen($chunk) ? $chunk : substr($chunk, 0, $room);
                        }
                    }
                }
                break;
            }
        }

        if ($timedOut) {
            self::terminateTree($process);
            proc_close($process);
            throw new CompilerOracleToolFailure(
                'The TypePHP compiler did not finish within ' . $timeoutSeconds
                . ' seconds and was killed. Raise TYPEPHP_MCP_COMPILE_TIMEOUT for huge projects.'
            );
        }
        $code = proc_close($process);
        return ['code' => $code, 'output' => $output, 'timedOut' => false];
    }

    /**
     * Kill the compiler and its descendants. Windows gets a real tree kill
     * (taskkill /T); POSIX terminates the direct child, which is the only
     * process tpc --dry spawns (no make/link in this stage).
     */
    private static function terminateTree(mixed $process): void
    {
        if (!is_resource($process)) {
            return;
        }
        $status = @proc_get_status($process);
        $pid = is_array($status) && isset($status['pid']) ? (int) $status['pid'] : 0;
        @proc_terminate($process, 15);
        usleep(200000);
        if (stripos(PHP_OS, 'WIN') === 0) {
            if ($pid > 0) {
                $discard = [];
                @exec('taskkill /PID ' . $pid . ' /T /F 2>nul', $discard, $discardCode);
            }
        } else {
            $status = @proc_get_status($process);
            if (is_array($status) && $status['running']) {
                @proc_terminate($process, 9);
            }
        }
    }

    /**
     * Render the check result. The status line names the stage that actually
     * ran and the diagnostic count; diagnostics carry file, line, column and
     * severity parsed from the compiler output.
     *
     * @param array{code: int, output: string, timedOut: bool} $result
     */
    private static function format(string $file, RepoRoot $root, array $result): string
    {
        $diagnostics = self::parseDiagnostics($result['output'], $root);
        $relative = $root->relativeFrom($file);
        if ($result['code'] === 0) {
            $lines = [
                'OK stage=' . self::STAGE_GENERATE . ' file=' . $relative . ' diagnostics=0',
                'Note: stage=generate runs `tpc --dry` (C++ generation only). The compile and link stages are not run, so their errors are not covered.',
            ];
            if ($diagnostics !== []) {
                $lines[] = 'Diagnostics:';
                $lines = array_merge($lines, self::renderDiagnostics($diagnostics));
            }
            return TextUtil::clip(implode("\n", $lines), 4000);
        }
        $lines = [
            'FAILED stage=' . self::STAGE_GENERATE . ' exit=' . $result['code']
            . ' file=' . $relative . ' diagnostics=' . count($diagnostics),
        ];
        if ($diagnostics !== []) {
            $lines[] = 'Diagnostics:';
            $lines = array_merge($lines, self::renderDiagnostics($diagnostics));
        } else {
            $lines[] = 'Diagnostics: (none parsed; raw compiler output follows)';
        }
        foreach (self::keptLines($result['output']) as $line) {
            $lines[] = $line;
            if (count($lines) >= 40) {
                break;
            }
        }
        return TextUtil::clip(implode("\n", $lines), 4000);
    }

    /**
     * Parse "Fatal error: <message> in <file>:<line>[:<col>]" lines.
     *
     * @return array<int, array{severity: string, message: string, file: string, line: int, column: int}>
     */
    private static function parseDiagnostics(string $output, RepoRoot $root): array
    {
        $diagnostics = [];
        foreach (explode("\n", $output) as $line) {
            $matched = preg_match(
                '/^(Fatal error|Parse error|Compile error|Error|Warning|Notice):\s+(.+?)\s+in\s+(.+?):(\d+)(?::(\d+))?\s*$/',
                $line,
                $matches
            );
            if ($matched !== 1) {
                continue;
            }
            $diagnostics[] = [
                'severity' => self::severityFor($matches[1]),
                'message' => $matches[2],
                'file' => self::relativeDiagnosticPath($matches[3], $root),
                'line' => (int) $matches[4],
                'column' => isset($matches[5]) && $matches[5] !== '' ? (int) $matches[5] : 0,
            ];
        }
        return $diagnostics;
    }

    private static function severityFor(string $label): string
    {
        if (str_starts_with($label, 'Warning')) {
            return 'warning';
        }
        if (str_starts_with($label, 'Notice')) {
            return 'notice';
        }
        return 'error';
    }

    private static function relativeDiagnosticPath(string $path, RepoRoot $root): string
    {
        $normalized = str_replace('\\', '/', $path);
        $real = realpath($normalized);
        $candidate = is_string($real) && $real !== '' ? str_replace('\\', '/', $real) : $normalized;
        if (str_starts_with($candidate, $root->path() . '/')) {
            return $root->relativeFrom($candidate);
        }
        return $candidate;
    }

    /**
     * @param array<int, array{severity: string, message: string, file: string, line: int, column: int}> $diagnostics
     * @return array<int, string>
     */
    private static function renderDiagnostics(array $diagnostics): array
    {
        $lines = [];
        foreach ($diagnostics as $diagnostic) {
            $lines[] = '- [' . $diagnostic['severity'] . '] ' . $diagnostic['file'] . ':'
                . $diagnostic['line'] . ($diagnostic['column'] > 0 ? ':' . $diagnostic['column'] : '')
                . ' ' . $diagnostic['message'];
        }
        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private static function keptLines(string $output): array
    {
        $kept = [];
        foreach (explode("\n", $output) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (str_starts_with($trimmed, '#')) {
                continue;
            }
            if (str_starts_with($trimmed, 'Stack trace')) {
                continue;
            }
            if (str_starts_with($trimmed, 'thrown in')) {
                continue;
            }
            if (str_starts_with($trimmed, 'Initialized platform')) {
                continue;
            }
            $kept[] = $trimmed;
            if (count($kept) >= 20) {
                break;
            }
        }
        return $kept;
    }

    private static function timeoutSeconds(?int $explicit): int
    {
        if ($explicit !== null) {
            return $explicit;
        }
        $override = getenv('TYPEPHP_MCP_COMPILE_TIMEOUT');
        if (is_string($override) && $override !== '' && is_numeric($override)) {
            $value = (int) $override;
            if ($value > 0) {
                return $value;
            }
        }
        return self::DEFAULT_TIMEOUT_SECONDS;
    }

    private static function outputLimit(): int
    {
        $override = getenv('TYPEPHP_MCP_COMPILE_OUTPUT_LIMIT');
        if (is_string($override) && $override !== '' && is_numeric($override)) {
            $value = (int) $override;
            if ($value > 0) {
                return $value;
            }
        }
        return self::DEFAULT_OUTPUT_LIMIT_BYTES;
    }

    private static function memoryLimitMb(): int
    {
        $override = getenv('TYPEPHP_MCP_COMPILE_MEMORY_LIMIT_MB');
        if (is_string($override) && $override !== '' && is_numeric($override)) {
            $value = (int) $override;
            if ($value > 0) {
                return $value;
            }
        }
        return self::DEFAULT_MEMORY_LIMIT_MB;
    }

    /**
     * MCP clients do not always inherit a developer PATH, but tpc resolves the
     * host toolchain before doing anything else. Prepend the MSVC bin located
     * through vswhere when no cl is reachable; putenv propagates to the
     * proc_open() children of this process on Windows.
     */
    private static function ensureHostToolchain(): void
    {
        if (stripos(PHP_OS, 'WIN') !== 0) {
            return;
        }
        if (self::hasClOnPath()) {
            return;
        }
        $binDir = self::findMsvcBinDir();
        if ($binDir === null) {
            return;
        }
        $path = getenv('PATH');
        putenv('PATH=' . $binDir . ';' . (is_string($path) ? $path : ''));
    }

    private static function hasClOnPath(): bool
    {
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return false;
        }
        foreach (explode(';', $path) as $dir) {
            if ($dir !== '' && is_file($dir . '\cl.exe')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Locate the newest MSVC x64 host bin directory via vswhere.
     */
    protected static function findMsvcBinDir(): ?string
    {
        $programFiles = getenv('ProgramFiles(x86)');
        if (!is_string($programFiles) || $programFiles === '') {
            // Standard x64 Windows location; the variable may be absent when a
            // client sanitizes the environment.
            $programFiles = 'C:\Program Files (x86)';
        }
        $vswhere = $programFiles . '\Microsoft Visual Studio\Installer\vswhere.exe';
        if (!is_file($vswhere)) {
            return null;
        }
        $output = [];
        $code = -1;
        exec('"' . $vswhere . '" -latest -products * -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath 2>nul', $output, $code);
        $installPath = count($output) > 0 && is_string($output[0]) ? trim($output[0]) : '';
        if ($installPath === '' || !is_dir($installPath)) {
            return null;
        }
        $msvcRoot = $installPath . '\VC\Tools\MSVC';
        if (!is_dir($msvcRoot)) {
            return null;
        }
        $versions = scandir($msvcRoot);
        if (!is_array($versions)) {
            return null;
        }
        $latest = '';
        foreach ($versions as $version) {
            if ($version === '.' || $version === '..') {
                continue;
            }
            if (is_dir($msvcRoot . '\\' . $version) && strcmp($version, $latest) > 0) {
                $latest = $version;
            }
        }
        if ($latest === '') {
            return null;
        }
        $binDir = $msvcRoot . '\\' . $latest . '\bin\Hostx64\x64';
        return is_dir($binDir) ? $binDir : null;
    }

    public static function phpBinary(): string
    {
        $override = getenv('TYPEPHP_MCP_PHP');
        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }
        try {
            $result = self::runProcess('php -v', self::PROBE_TIMEOUT_SECONDS, 8192);
        } catch (CompilerOracleToolFailure $error) {
            return '';
        }
        if ($result['code'] === 0) {
            return 'php';
        }
        return '';
    }

    /**
     * Temporary build directory for a --dry run. sys_get_temp_dir() can
     * resolve to an unusable location when a client strips TMP/TEMP; the
     * writability check plus the mkdir retry in check() cover that.
     */
    private static function buildDir(): string
    {
        $base = sys_get_temp_dir();
        if (!is_dir($base) || !is_writable($base)) {
            $base = self::fallbackTempDir();
        }
        return rtrim($base, '/\\') . '/typephp-mcp-dry-' . uniqid();
    }

    private static function fallbackTempDir(): string
    {
        return str_starts_with(PHP_OS, 'WIN') ? 'C:\\Windows\\Temp' : '/tmp';
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

<?php

/**
 * L3 compiler oracle: run `php bin/tpc.php --dry` on a file.
 *
 * --dry only generates C++, so it validates the TypePHP subset (the #1 AI
 * failure mode) without compiling or linking. Link-time errors are not
 * covered; the output says so.
 *
 * Note: tpc still detects the host toolchain at startup (it fatals when no
 * C/C++ compiler is on PATH), so check_code makes sure an MSVC cl is
 * reachable before spawning the compiler.
 */
final class CompilerOracle
{
    public static function check(string $file, RepoRoot $root): string
    {
        $resolved = $file;
        if (!is_file($resolved)) {
            $inside = $root->resolveInside($file);
            $resolved = is_string($inside) ? $inside : '';
        }
        if ($resolved === '' || !is_file($resolved)) {
            return 'File not found: ' . $file . "\nPass an absolute path, or a path relative to the repo root.";
        }

        $php = self::phpBinary();
        if ($php === '') {
            return "No PHP binary found on PATH. Set TYPEPHP_MCP_PHP to a php executable.\n"
                . 'check_code needs `php bin/tpc.php --dry` to run the compiler oracle.';
        }

        self::ensureHostToolchain();

        $buildDir = sys_get_temp_dir() . '/typephp-mcp-dry-' . uniqid();
        if (!mkdir($buildDir, 0777, true)) {
            return 'Cannot create build directory: ' . $buildDir;
        }

        $command = escapeshellarg($php)
            . ' ' . escapeshellarg($root->path() . '/bin/tpc.php')
            . ' --dry ' . escapeshellarg($resolved)
            . ' --build-dir ' . escapeshellarg($buildDir)
            . ' --no-color --no-progress 2>&1';
        $output = [];
        $code = -1;
        exec($command, $output, $code);
        self::removeDir($buildDir);

        return self::format($resolved, $code, $output);
    }

    /**
     * MCP clients do not always inherit a developer PATH, but tpc resolves the
     * host toolchain before doing anything else. Prepend the MSVC bin located
     * through vswhere when no cl is reachable; putenv propagates to the
     * exec()/proc_open() children of this process on Windows.
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
        $output = [];
        $code = -1;
        exec('php -v 2>&1', $output, $code);
        if ($code === 0 && count($output) > 0) {
            return 'php';
        }
        return '';
    }

    private static function format(string $file, int $code, array $output): string
    {
        $kept = [];
        foreach ($output as $line) {
            if (!is_string($line)) {
                continue;
            }
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
        }
        if (count($kept) > 40) {
            $kept = array_slice($kept, 0, 40);
        }
        $body = implode("\n", $kept);
        if ($code === 0) {
            return 'OK: ' . $file . " passes `tpc --dry`.\n"
                . "Note: --dry covers C++ generation only; link-time errors are not checked.\n" . $body;
        }
        return 'FAILED (exit ' . $code . '): ' . $file . "\n" . $body;
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

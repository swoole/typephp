<?php

namespace TypePhp\Platform;

/**
 * Base class for Unix-like platforms (Linux, macOS).
 * Contains the shared implementation of common GCC/Clang flag syntax.
 */
abstract class UnixPlatform extends PlatformBase
{
    /** @var array<string, string> */
    private array $phpDirectoryCache = [];
    /** @var array<string, string> */
    private array $phpConfigCache = [];
    /** @var array<string, list<string>> */
    private array $phpIncludeCache = [];
    /** @var array<string, string> */
    private array $phpConfigValueCache = [];

    private function getPhpSdkCacheKey(string $directory = ''): string
    {
        // Scope discovery to this platform instance and the selected environment.
        // Constructing every compile/cache-key command must not rerun php-config.
        return serialize([$directory, getenv('PHP_HOME'), getenv('PATH')]);
    }

    public function getSharedLinkFlag(): string
    {
        return '-shared';
    }

    public function getSubsystemOptions(bool $noConsole): string
    {
        return '';
    }

    public function getCrtConfig(): string
    {
        return '';
    }

    public function getTargetExtension(string $buildMode): string
    {
        if ($buildMode === 'lib') {
            return $this->getSharedLibraryExtension();
        }

        return parent::getTargetExtension($buildMode);
    }

    public function getIncludeFlags(array $includePaths): string
    {
        if (empty($includePaths)) {
            return '';
        }

        $flags = [];
        foreach ($includePaths as $path) {
            $flags[] = '-I' . escapeshellarg($path);
        }

        return implode(' ', $flags);
    }

    public function getLibraryPathFlags(array $libraryPaths): string
    {
        if (empty($libraryPaths)) {
            return '';
        }

        $flags = [];
        foreach ($libraryPaths as $path) {
            $flags[] = '-L' . escapeshellarg($path);
        }

        return implode(' ', $flags);
    }

    public function getLibraryFlags(array $libraries): string
    {
        if (empty($libraries)) {
            return '';
        }

        $ext = ltrim($this->getSharedLibraryExtension(), '.');
        $flags = [];
        foreach ($libraries as $lib) {
            $libName = basename($lib);
            if (str_starts_with($libName, 'lib')) {
                $libName = substr($libName, 3);
            }
            $libName = preg_replace('/\.(a|' . $ext . ')$/', '', $libName);

            $flags[] = '-l' . $libName;
        }

        return implode(' ', $flags);
    }

    public function getObjectExtension(): string
    {
        return '.o';
    }

    public function getExecutableExtension(): string
    {
        return '';
    }

    public function getPathSeparator(): string
    {
        return '/';
    }

    public function getPhpDir(): string
    {
        $key = $this->getPhpSdkCacheKey();
        return $this->phpDirectoryCache[$key] ??= $this->resolvePhpDir();
    }

    private function resolvePhpDir(): string
    {
        $phpDir = getenv('PHP_HOME');
        if (is_string($phpDir) && $phpDir !== '') {
            $phpDir = rtrim($phpDir, '\/');
            if (!is_dir($phpDir)) {
                throw new \RuntimeException("PHP_HOME is not a directory: {$phpDir}");
            }
            return $phpDir;
        }

        // On Ubuntu/PPA multi-version installations, php8.4 and php-config8.4 coexist,
        // while the unversioned php-config may be pointed at another version by
        // update-alternatives. Prefer locating the versioned php-config from the
        // version suffix of PHP_BINARY to avoid an ABI mismatch.
        $versionedConfig = $this->findVersionedPhpConfig(dirname(realpath(PHP_BINARY) ?: PHP_BINARY));
        if ($versionedConfig !== null) {
            $prefix = $this->getPhpConfigValue($versionedConfig, '--prefix');
            if ($prefix !== null && is_dir($prefix)) {
                return rtrim($prefix, '/');
            }
        }

        // Composer executes tpc.php with an already selected PHP binary. Use
        // that installation before consulting an unrelated php-config from
        // PATH, which may point at another PHP minor version or ABI.
        $runningPhp = realpath(PHP_BINARY) ?: PHP_BINARY;
        $runningPhpDir = dirname(dirname($runningPhp));
        if (is_executable($runningPhpDir . '/bin/php-config')) {
            return $runningPhpDir;
        }

        $phpDir = shell_exec('php-config --prefix 2>/dev/null');
        if (!empty($phpDir)) {
            return trim($phpDir);
        }

        $phpExe = trim(shell_exec('which php 2>/dev/null'));
        if ($phpExe && file_exists($phpExe)) {
            $phpDir = dirname(dirname($phpExe));
            if (is_dir($phpDir)) {
                return $phpDir;
            }
        }

        throw new \RuntimeException('The `php-config` is not found. Please install PHP development package or set PHP_HOME environment variable');
    }

    /**
     * libphpx and the embed libphp.so live where TypePHP put them: beside the
     * phpx checkout, and under the PHP prefix the libphp installer chose.
     * Neither is on the loader's search path, so a linked program only finds
     * them again if their directories are recorded in the binary.
     *
     * Cross-compilation targets override this back to an empty list: a path on
     * the build host means nothing on the device that runs the output.
     */
    public function getDefaultRpaths(?string $phpxDir = null, ?string $phpDir = null): array
    {
        $rpaths = [];

        if ($phpxDir !== null) {
            $phpxLibDir = $phpxDir . '/lib';
            if (is_dir($phpxLibDir)) {
                $rpaths[] = $phpxLibDir;
            }
        }

        if ($phpDir !== null) {
            $phpLibDir = $this->resolvePhpLibDir($phpDir);
            if ($phpLibDir !== null) {
                $rpaths[] = $phpLibDir;
            }
        }

        return $rpaths;
    }

    /**
     * Get the RPATH options.
     */
    public function getRpathOptions(array $paths): string
    {
        if (empty($paths)) {
            return '';
        }

        $rpaths = [];
        foreach ($paths as $path) {
            $rpaths[] = '-Wl,-rpath,' . escapeshellarg($path);
        }

        return implode(' ', $rpaths);
    }

    /**
     * Get the PIC option.
     */
    public function getPicFlag(): string
    {
        return '-fPIC';
    }

    /**
     * Build the PHP include paths (obtained dynamically via php-config).
     */
    public function buildPhpIncludePaths(string $phpDir): array
    {
        $key = $this->getPhpSdkCacheKey($phpDir);
        return $this->phpIncludeCache[$key] ??= $this->resolvePhpIncludePaths($phpDir);
    }

    private function resolvePhpIncludePaths(string $phpDir): array
    {
        $phpConfigPath = $this->findPhpConfig($phpDir);
        if ($phpConfigPath) {
            $includes = shell_exec(escapeshellarg($phpConfigPath) . ' --includes 2>/dev/null');
            if ($includes) {
                preg_match_all('/-I([^\s]+)/', $includes, $matches);
                if (!empty($matches[1])) {
                    $includePaths = [];
                    foreach ($matches[1] as $path) {
                        if (is_dir($path)) {
                            $includePaths[] = $path;
                        }
                    }
                    return $includePaths;
                }
            }
        }

        $paths = [
            $phpDir . '/include/php',
            $phpDir . '/include/php/main',
            $phpDir . '/include/php/TSRM',
            $phpDir . '/include/php/Zend',
            $phpDir . '/include/php/ext',
        ];

        $includePaths = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $includePaths[] = $path;
            }
        }

        return $includePaths;
    }

    /**
     * Locate the php-config executable.
     */
    protected function findPhpConfig(string $phpDir): ?string
    {
        $key = $this->getPhpSdkCacheKey($phpDir);
        // Do not cache failed discovery: an SDK may be installed afterwards.
        return $this->phpConfigCache[$key] ??= $this->resolvePhpConfig($phpDir);
    }

    private function resolvePhpConfig(string $phpDir): ?string
    {
        $candidates = [];
        $phpDir = rtrim($phpDir, '/');

        $phpHome = getenv('PHP_HOME');
        if (is_string($phpHome) && $phpHome !== '') {
            $phpHome = rtrim($phpHome, '/');
            $expected = realpath($phpHome) ?: $phpHome;
            $actual = realpath($phpDir) ?: $phpDir;
            if ($actual === $expected) {
                // PHP_HOME is authoritative. Ubuntu/PPA installs several PHP
                // versions under /usr, so prefer php-config8.x over the
                // unversioned php-config selected by update-alternatives.
                $versioned = $this->findVersionedPhpConfig($phpDir . '/bin');
                if ($versioned !== null) {
                    $candidates[] = $versioned;
                }
                $candidate = $phpDir . '/bin/php-config';
                if (is_executable($candidate)) {
                    $candidates[] = $candidate;
                }

                if ($candidates === []) {
                    throw new \RuntimeException(
                        "PHP_HOME does not provide an executable bin/php-config: {$phpDir}"
                    );
                }

                foreach (array_unique($candidates) as $config) {
                    if ($this->phpConfigMatchesCurrentPhp($config)) {
                        return $config;
                    }
                }
                $this->reportPhpConfigVersionMismatch($candidates[0]);
            }
        }

        // Prefer the config belonging to the requested installation. If its
        // unversioned config belongs to another PHP, the version check below
        // will continue with the config beside the running PHP binary.
        $candidate = $phpDir . '/bin/php-config';
        if (is_executable($candidate)) {
            $candidates[] = $candidate;
        }

        $versioned = $this->findVersionedPhpConfig(dirname(realpath(PHP_BINARY) ?: PHP_BINARY));
        if ($versioned !== null) {
            $candidates[] = $versioned;
        }

        // Validate preferred installations before invoking the PATH fallback.
        foreach (array_unique($candidates) as $config) {
            if ($this->phpConfigMatchesCurrentPhp($config)) {
                return $config;
            }
        }

        // PATH is only a fallback, and its prefix must match the selected PHP.
        $whichResult = trim(shell_exec('which php-config 2>/dev/null'));
        if ($whichResult && is_executable($whichResult)) {
            $prefix = $this->getPhpConfigValue($whichResult, '--prefix');
            $expected = realpath($phpDir) ?: rtrim($phpDir, '/');
            $actual = $prefix === null ? null : (realpath($prefix) ?: rtrim($prefix, '/'));
            if ($actual === $expected) {
                $candidates[] = $whichResult;
            }
        }

        // Return the first candidate whose major/minor version matches the current PHP.
        foreach (array_unique($candidates) as $config) {
            if ($this->phpConfigMatchesCurrentPhp($config)) {
                return $config;
            }
        }

        // Report a clear error when candidates exist but none matches the version.
        if ($candidates !== []) {
            $this->reportPhpConfigVersionMismatch($candidates[0]);
        }

        return null;
    }

    /** Find php-config8.x for the PHP version executing the compiler. */
    private function findVersionedPhpConfig(string $binDir): ?string
    {
        $candidate = rtrim($binDir, '/') . '/php-config' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        return is_executable($candidate) ? $candidate : null;
    }

    /**
     * Verify that php-config's major/minor version matches the currently running PHP.
     */
    private function phpConfigMatchesCurrentPhp(string $phpConfig): bool
    {
        $version = $this->getPhpConfigValue($phpConfig, '--version');
        if ($version === null) {
            return false;
        }
        if (preg_match('/^(\d+\.\d+)\.\d+/', $version, $match)) {
            return $match[1] === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        }
        return false;
    }

    private function reportPhpConfigVersionMismatch(string $phpConfig): void
    {
        $version = $this->getPhpConfigValue($phpConfig, '--version') ?? 'unknown';
        throw new \RuntimeException(sprintf(
            "The `php-config` (%s) reports PHP %s, but the running PHP is %s. " .
            'Set PHP_HOME to the matching PHP installation.',
            $phpConfig,
            $version,
            PHP_VERSION,
        ));
    }

    protected function getPhpConfigValue(string $phpConfig, string $option): ?string
    {
        $key = $this->getPhpSdkCacheKey($phpConfig) . "\0" . $option;
        if (isset($this->phpConfigValueCache[$key])) {
            return $this->phpConfigValueCache[$key];
        }
        $value = shell_exec(escapeshellarg($phpConfig) . ' ' . escapeshellarg($option) . ' 2>/dev/null');
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return $this->phpConfigValueCache[$key] = trim($value);
    }

    protected function resolvePhpLibDir(string $phpDir): ?string
    {
        $phpConfig = $this->findPhpConfig($phpDir);
        if ($phpConfig !== null) {
            $libDir = $this->getPhpConfigValue($phpConfig, '--lib-dir');
            if ($libDir !== null && is_dir($libDir)) {
                return rtrim($libDir, '/');
            }
        }

        $libDir = rtrim($phpDir, '/') . '/lib';
        return is_dir($libDir) ? $libDir : null;
    }

    /**
     * Build the PHP library paths.
     */
    public function buildPhpLibPaths(string $phpDir): array
    {
        $libPath = $this->resolvePhpLibDir($phpDir);
        return $libPath === null ? [] : [$libPath];
    }

    /**
     * Detect the PHP library files.
     */
    public function detectPhpLibs(string $phpDir): array
    {
        $libPath = $this->resolvePhpLibDir($phpDir);
        if ($libPath === null) {
            throw new \RuntimeException("PHP library directory not found for installation: {$phpDir}");
        }

        $ext = ltrim($this->getSharedLibraryExtension(), '.');
        $embedLib = null;
        $staticLib = null;

        $phpConfig = $this->findPhpConfig($phpDir);
        $configuredEmbed = $phpConfig === null ? null : $this->getPhpConfigValue($phpConfig, '--lib-embed');
        if ($configuredEmbed !== null) {
            $configuredPath = str_starts_with($configuredEmbed, '/')
                ? $configuredEmbed
                : $libPath . '/' . $configuredEmbed;
            if (is_file($configuredPath)) {
                if (str_ends_with($configuredPath, '.a')) {
                    $staticLib = $configuredPath;
                } else {
                    $embedLib = $configuredPath;
                }
            }
        }

        if ($embedLib === null && $staticLib === null) {
            $sharedCandidate = $libPath . '/libphp.' . $ext;
            $staticCandidate = $libPath . '/libphp.a';
            $embedLib = is_file($sharedCandidate) ? $sharedCandidate : null;
            $staticLib = is_file($staticCandidate) ? $staticCandidate : null;
        }

        $hasEmbed = $embedLib !== null;
        $hasStatic = $staticLib !== null;

        if (!$hasEmbed && !$hasStatic) {
            throw new \RuntimeException("Neither libphp.{$ext} nor libphp.a found");
        }

        return [
            'embed' => $hasEmbed ? $embedLib : null,
            'static' => $hasStatic ? $staticLib : null,
            'is_shared' => $hasEmbed,
        ];
    }
}

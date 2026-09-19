<?php

namespace TypePhp\Installer;

use TypePhp\Platform\Linux;

final class LibPhpInstaller
{
    private const string RELEASE_API = 'https://www.php.net/releases/index.php?json=1&version=%s&max=100';
    private ?string $sourcePhpDir = null;

    public function __construct(private readonly InteractiveConsole $console = new InteractiveConsole())
    {
    }

    public function ensure(string $currentPhpDir): ?string
    {
        $this->sourcePhpDir = rtrim($currentPhpDir, '/');
        if (PHP_OS_FAMILY !== 'Linux' || $this->hasLibPhp($currentPhpDir)) {
            return $currentPhpDir;
        }
        if (!$this->console->isInteractive()) {
            $this->console->write('libphp.so is missing. Run tpc.php in an interactive terminal to build it automatically, or set PHP_HOME.');
            return null;
        }

        $this->console->write("The current PHP installation does not provide libphp.so: {$currentPhpDir}");

        $home = getenv('HOME') ?: (string) ($_SERVER['HOME'] ?? '');
        $defaultPrefix = rtrim($home, '/') . '/.typephp';
        $defaultVersion = PHP_VERSION;

        // A build left by an earlier run answers every question below, so offer
        // it before asking any of them. Both defaults are known without asking:
        // the directory this installer always proposes, and the running PHP.
        if ($this->offerInstalled($defaultPrefix, $defaultVersion)) {
            return $this->activate($defaultPrefix);
        }

        if (!$this->console->confirm('Build a private PHP embed library now?', true)) {
            return null;
        }

        $version = $this->console->ask("PHP version [{$defaultVersion}]: ", $defaultVersion);
        if (!preg_match('/^8\.[45]\.\d+$/', $version)) {
            throw new \RuntimeException('Only stable PHP 8.4.x and 8.5.x versions are supported by the automatic installer');
        }

        $prefix = $this->expandHome($this->console->ask("Install directory [{$defaultPrefix}]: ", $defaultPrefix), $home);
        // The offer above covered only the default directory and the running
        // PHP; the answers just given may name another build.
        if (($prefix !== $defaultPrefix || $version !== $defaultVersion)
            && $this->offerInstalled($prefix, $version)) {
            return $this->activate($prefix);
        }
        // Reaching php.net is pointless until a build is known to be needed.
        $this->install($this->release($version), $prefix);
        return $this->activate($prefix);
    }

    private function offerInstalled(string $prefix, string $version): bool
    {
        return $this->hasLibPhp($prefix)
            && $this->installedVersion($prefix) === $version
            && $this->console->confirm("PHP {$version} with libphp.so already exists in {$prefix}; use it?", true);
    }

    /** Point this process, and the build it runs, at the selected installation. */
    private function activate(string $prefix): string
    {
        putenv('PHP_HOME=' . $prefix);
        $_ENV['PHP_HOME'] = $prefix;
        return $prefix;
    }

    public function hasLibPhp(string $prefix): bool
    {
        try {
            (new Linux())->detectPhpLibs(rtrim($prefix, '/'));
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function installedVersion(string $prefix): ?string
    {
        $phpConfig = $prefix . '/bin/php-config';
        if (!is_executable($phpConfig)) {
            return null;
        }
        $version = trim((string) shell_exec(escapeshellarg($phpConfig) . ' --version 2>/dev/null'));
        return preg_match('/^\d+\.\d+\.\d+/', $version, $match) ? $match[0] : null;
    }

    /** @return array{version:string,filename:string,sha256:string,url:string} */
    public function latestRelease(): array
    {
        $releases = $this->fetchReleaseList('8.4');
        uksort($releases, static fn(string $a, string $b): int => version_compare($b, $a));
        foreach ($releases as $version => $info) {
            if (preg_match('/^8\.4\.\d+$/', $version)) {
                return $this->normalizeRelease($version, $info);
            }
        }
        throw new \RuntimeException('PHP.net did not return a stable PHP 8.4 release');
    }

    /** @return array{version:string,filename:string,sha256:string,url:string} */
    public function release(string $version): array
    {
        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        $releases = $this->fetchReleaseList($branch);
        if (!isset($releases[$version])) {
            throw new \RuntimeException("PHP {$version} was not found in the official release list");
        }
        return $this->normalizeRelease($version, $releases[$version]);
    }

    private function fetchReleaseList(string $branch): array
    {
        $json = $this->downloadText(sprintf(self::RELEASE_API, rawurlencode($branch)));
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid release list returned by PHP.net');
        }
        return $data;
    }

    private function normalizeRelease(string $version, array $info): array
    {
        foreach ($info['source'] ?? [] as $source) {
            $filename = (string) ($source['filename'] ?? '');
            if (str_ends_with($filename, '.tar.xz') && !empty($source['sha256'])) {
                return [
                    'version' => $version,
                    'filename' => $filename,
                    'sha256' => (string) $source['sha256'],
                    'url' => 'https://www.php.net/distributions/' . rawurlencode($filename),
                ];
            }
        }
        throw new \RuntimeException("PHP {$version} has no verified tar.xz source archive");
    }

    private function install(array $release, string $prefix): void
    {
        $workDir = $prefix . '/var/build';
        $archive = $workDir . '/' . $release['filename'];
        $sourceDir = $workDir . '/php-' . $release['version'];
        $this->mkdir($workDir);
        $this->mkdir($prefix . '/lib/conf.d');

        $configureOptions = $this->currentConfigureOptions();
        $options = PhpBuildConfiguration::derive($configureOptions, $prefix);
        $manager = LinuxPackageManager::detect();
        if ($manager !== null) {
            $packages = $manager->missingPackages($manager->packagesForConfigureOptions($options));
            $this->console->write('Detected package manager: ' . $manager->command);
            if ($packages !== [] && $this->console->confirm('Install missing development packages (' . implode(', ', $packages) . ')?', true)) {
                $useSudo = function_exists('posix_geteuid') && posix_geteuid() !== 0;
                $refresh = $manager->refreshCommand($useSudo);
                if ($refresh !== null) {
                    $this->run($refresh);
                }
                $this->run($manager->installCommand($packages, $useSudo));
            } elseif ($packages === []) {
                $this->console->write('All detected build dependencies are already installed.');
            }
        } else {
            $this->console->write('No supported package manager (apt-get/dnf/yum) was found; continuing with existing libraries.');
        }

        if (!is_file($archive) || hash_file('sha256', $archive) !== $release['sha256']) {
            $this->console->write('Downloading ' . $release['url']);
            $this->downloadFile($release['url'], $archive);
        }
        if (hash_file('sha256', $archive) !== $release['sha256']) {
            throw new \RuntimeException('PHP source archive SHA-256 verification failed');
        }
        if (!is_dir($sourceDir)) {
            $this->run(['tar', '-xJf', $archive, '-C', $workDir]);
        }

        $this->console->write('Configuring PHP with the current installation options plus --enable-embed=shared');
        $this->run([$sourceDir . '/configure', ...$options], $sourceDir);
        // PHP is a large build; capping parallelism avoids exhausting memory on
        // hosts that expose many CPUs (especially containers and CI runners).
        $jobs = min(8, max(1, $this->cpuCount()));
        $this->run(['make', '-j' . $jobs], $sourceDir);
        $this->run(['make', 'install'], $sourceDir);
        if (!$this->hasLibPhp($prefix)) {
            throw new \RuntimeException("Build completed but {$prefix}/lib/libphp.so was not created");
        }
        $this->writePhpIni($prefix, $sourceDir, $release['version']);
        $this->console->write("libphp.so installed successfully in {$prefix}/lib");
    }

    /** @return list<string> */
    private function currentConfigureOptions(): array
    {
        // Prefer the "Configure Command:" output from `PHP_BINARY -i`, which preserves
        // exact argument boundaries. In contrast, `php-config --configure-options` drops
        // quotes around space-containing build assignments such as `CFLAGS=-g -O2`.
        $info = $this->capture([PHP_BINARY, '-n', '-i']);
        if (preg_match('/^Configure Command =>\s*(.+)$/mi', $info, $match)) {
            $words = PhpBuildConfiguration::parseShellWords(trim($match[1]));
            if (isset($words[0]) && basename($words[0]) === 'configure') {
                array_shift($words);
            }
            return $words;
        }

        // Fallback: php-config --configure-options. On PPA multi-version installations
        // several PHP versions share the /usr prefix, so when PHP_HOME=/usr the
        // versioned php-config8.x must be preferred.
        $versionedPhpConfig = $this->sourcePhpDir . '/bin/php-config'
            . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if ($this->sourcePhpDir !== null && is_executable($versionedPhpConfig)) {
            $phpConfig = $versionedPhpConfig;
        } elseif ($this->sourcePhpDir !== null && is_executable($this->sourcePhpDir . '/bin/php-config')) {
            $phpConfig = $this->sourcePhpDir . '/bin/php-config';
        } else {
            $phpConfig = trim((string) shell_exec('command -v php-config 2>/dev/null'));
        }
        if ($phpConfig !== '') {
            return PhpBuildConfiguration::parsePhpConfigOptions(
                trim($this->capture([$phpConfig, '--configure-options']))
            );
        }

        throw new \RuntimeException('Unable to determine the current PHP configure options from php-config or php -i');
    }

    private function writePhpIni(string $prefix, string $sourceDir, string $version): void
    {
        $chunks = [];
        $loaded = php_ini_loaded_file();
        if (is_string($loaded) && is_file($loaded)) {
            $chunks[] = file_get_contents($loaded);
        } elseif (is_file($sourceDir . '/php.ini-development')) {
            $chunks[] = file_get_contents($sourceDir . '/php.ini-development');
        }
        $scanned = php_ini_scanned_files();
        if (is_string($scanned) && trim($scanned) !== '') {
            foreach (preg_split('/,\s*/', trim($scanned)) as $file) {
                if (is_file($file)) {
                    $chunks[] = PHP_EOL . '; imported from ' . $file . PHP_EOL . file_get_contents($file);
                }
            }
        }
        $ini = implode(PHP_EOL, $chunks);
        $extensionDir = trim($this->capture([$prefix . '/bin/php-config', '--extension-dir']));
        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        if ($branch === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) {
            $this->copyConfiguredExtensions($prefix, $extensionDir, $ini);
        } else {
            $this->console->write('Shared extensions cannot be copied across PHP minor versions; only extensions built from php-src will be enabled.');
        }
        $ini = $this->disableUnavailableExtensions($ini, $extensionDir);
        if (preg_match('/^\s*extension_dir\s*=.*$/mi', $ini)) {
            $ini = preg_replace('/^\s*extension_dir\s*=.*$/mi', 'extension_dir=' . $extensionDir, $ini, 1);
        } else {
            $ini .= PHP_EOL . 'extension_dir=' . $extensionDir . PHP_EOL;
        }
        file_put_contents($prefix . '/lib/php.ini', $ini);
    }

    private function disableUnavailableExtensions(string $ini, string $extensionDir): string
    {
        return preg_replace_callback(
            '/^(\s*(?:zend_)?extension\s*=\s*["\']?)([^"\'\s;]+)(["\']?.*)$/mi',
            static function (array $match) use ($extensionDir): string {
                $module = basename($match[2]);
                $basePath = rtrim($extensionDir, '/') . '/' . $module;
                return self::firstExistingFile(self::extensionFileCandidates($basePath)) !== null
                    ? $match[1] . $module . $match[3]
                    : '; disabled by TypePHP (module was not built): ' . $match[0];
            },
            $ini
        );
    }

    private function copyConfiguredExtensions(string $prefix, string $targetDirectory, string $ini): void
    {
        $this->mkdir($targetDirectory);
        $manifest = [];
        preg_match_all('/^\s*(?:zend_)?extension\s*=\s*["\']?([^"\'\s;]+)["\']?/mi', $ini, $matches);
        $currentExtensionDir = (string) ini_get('extension_dir');
        foreach (array_unique($matches[1]) as $configuredPath) {
            $sourceBase = str_starts_with($configuredPath, '/')
                ? $configuredPath
                : rtrim($currentExtensionDir, '/') . '/' . $configuredPath;
            $source = self::firstExistingFile(self::extensionFileCandidates($sourceBase));
            if ($source === null) {
                continue;
            }
            $target = $targetDirectory . '/' . basename($source);
            if (!is_file($target) && !copy($source, $target)) {
                throw new \RuntimeException("Unable to copy loaded extension {$source}");
            }
            $manifest[] = $configuredPath . '=' . basename($source);
        }
        file_put_contents($prefix . '/lib/loaded-extensions.txt', implode(PHP_EOL, $manifest) . PHP_EOL);
    }

    public static function extensionFileCandidates(string $path): array
    {
        return str_ends_with($path, '.so') ? [$path] : [$path, $path . '.so'];
    }

    private static function firstExistingFile(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private function downloadText(string $url): string
    {
        $context = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'TypePHP/tpc']]);
        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            $curl = trim((string) shell_exec('command -v curl 2>/dev/null'));
            if ($curl !== '') {
                return $this->capture([$curl, '--fail', '--location', '--retry', '3', $url]);
            }
            throw new \RuntimeException("Unable to download {$url}; enable allow_url_fopen or install curl");
        }
        return $data;
    }

    private function downloadFile(string $url, string $target): void
    {
        $curl = trim((string) shell_exec('command -v curl 2>/dev/null'));
        if ($curl !== '') {
            $this->run([$curl, '--fail', '--location', '--retry', '3', '--output', $target, $url]);
            return;
        }
        $data = $this->downloadText($url);
        if (file_put_contents($target, $data) === false) {
            throw new \RuntimeException("Unable to write {$target}");
        }
    }

    private function run(array $command, ?string $cwd = null): void
    {
        $this->console->write('$ ' . implode(' ', array_map('escapeshellarg', $command)));
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $cwd);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new \RuntimeException('Command failed: ' . implode(' ', $command));
        }
    }

    private function capture(array $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to run command: ' . implode(' ', $command));
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException(trim($stderr));
        }
        return $stdout;
    }

    private function mkdir(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create directory {$directory}");
        }
    }

    private function expandHome(string $path, string $home): string
    {
        return str_starts_with($path, '~/') ? rtrim($home, '/') . substr($path, 1) : rtrim($path, '/');
    }

    private function cpuCount(): int
    {
        $count = (int) trim((string) shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null'));
        return $count > 0 ? $count : 1;
    }
}

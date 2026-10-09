<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

use TypePhp\Installer\PhpBuildConfiguration;

final class SapiPhpBuilder
{
    private readonly \Closure $output;
    private readonly ?\Closure $progress;
    private ?CompilerToolchain $activeToolchain = null;

    /**
     * @param callable(string):void $output
     * @param null|callable(int, int, string, bool):void $progress
     */
    public function __construct(
        private readonly string $phpxSourceDirectory,
        callable $output,
        private readonly ?string $proxy = null,
        ?callable $progress = null,
        private readonly ?CompilerToolchain $toolchain = null,
    ) {
        $this->output = \Closure::fromCallable($output);
        $this->progress = $progress === null ? null : \Closure::fromCallable($progress);
    }

    /**
     * @param list<string> $targets
     * @param list<string> $requiredExtensions
     */
    public function prepare(
        string $phpVersion,
        array $targets,
        int $jobs,
        array $requiredExtensions = [],
        bool $zts = false,
        bool $debug = false,
    ): SapiPhpBuild {
        if (PHP_OS_FAMILY !== 'Linux' && PHP_OS_FAMILY !== 'Darwin') {
            throw new \RuntimeException('Self-contained SAPI builds currently require Linux or macOS');
        }
        if ($targets === [] || array_diff($targets, ['embed', 'cli', 'fpm']) !== []) {
            throw new \InvalidArgumentException('PHP builder SAPI targets must contain embed, cli, or fpm');
        }
        $this->activeToolchain = $this->toolchain ?? new CompilerToolchain(
            getenv('CC') ?: 'cc',
            getenv('CXX') ?: 'c++',
            getenv('AR') ?: 'ar',
            CompilerToolchain::ARCHIVER_UNIX,
        );
        $officialSource = (new OfficialPhpSource(
            OfficialPhpSource::defaultCacheDirectory(),
            $this->output,
            $this->proxy,
        ))->prepare($phpVersion);
        $preparedSource = (new PhpBuilderSource(
            OfficialPhpSource::defaultCacheDirectory(),
            $this->output,
            $this->proxy,
        ))->prepare($officialSource, $requiredExtensions);
        $source = $preparedSource['source'];
        $externalExtensions = $preparedSource['external'];
        $sourceVersion = OfficialPhpSource::version($source);
        $requiredExtensions = SapiExtensionRequirements::merge($requiredExtensions);
        $extensionOptions = SapiExtensionConfiguration::configureOptions($source, $requiredExtensions);
        $runtimeTargets = array_values(array_unique($targets));
        sort($runtimeTargets, SORT_STRING);
        $identity = [
            $sourceVersion,
            '--disable-all',
            $requiredExtensions,
            $extensionOptions,
            $runtimeTargets,
            $zts,
            $debug,
            $externalExtensions,
            PHP_OS_FAMILY,
            php_uname('m'),
            $this->activeToolchain->cCompiler,
            $this->activeToolchain->cxxCompiler,
            $this->activeToolchain->archiver,
            filemtime($source . '/configure'),
        ];
        $compatibility = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $cacheDirectory = OfficialPhpSource::defaultCacheDirectory() . '/php-builder';
        $cached = $this->findCompatibleRuntime(
            $cacheDirectory,
            $sourceVersion,
            $compatibility,
            $requiredExtensions,
        );
        if ($cached !== null) {
            ($this->output)('Reusing private PHP runtime with extensions: '
                . ($requiredExtensions === [] ? 'core' : implode(', ', $requiredExtensions)));
            return $cached;
        }

        $fingerprint = substr($compatibility, 0, 16);
        $root = $cacheDirectory . '/php-'
            . $sourceVersion . '-' . $fingerprint;
        $this->mkdir($root);
        $lock = fopen($root . '/build.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException("Unable to lock PHP builder runtime cache: {$root}");
        }
        try {
            $build = $root . '/build';
            $prefix = $root . '/install';
            $this->mkdir($build);
            $this->mkdir($prefix . '/lib/conf.d');

            $options = PhpBuildConfiguration::derivePhpBuilder(
                $extensionOptions,
                $prefix,
                $runtimeTargets,
                $zts,
                $debug,
            );
            $configured = $build . '/.typephp-configure.json';
            $configuration = json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (!is_file($build . '/Makefile') || @file_get_contents($configured) !== $configuration) {
                ($this->output)('Configuring PHP ' . $sourceVersion . ' source runtime');
                $this->run([$source . '/configure', ...$options], $build);
                AtomicFile::write($configured, $configuration);
            }

            $php = $prefix . '/bin/php';
            $fpm = $prefix . '/sbin/php-fpm';
            $embed = $prefix . '/lib/libphp.a';
            $needsInstall = !is_executable($php)
                || (in_array('fpm', $runtimeTargets, true) && !is_executable($fpm))
                || (in_array('embed', $runtimeTargets, true) && !is_file($embed));
            if ($needsInstall) {
                ($this->output)('Building private PHP runtime (cached across application builds)');
                $this->run(['make', '-j' . max(1, $jobs)], $build);
                ($this->output)('Installing private PHP runtime');
                $this->run(['make', 'install'], $build);
            }

            $sapiArchives = $this->buildSapiArchives($build, $root, $jobs, $runtimeTargets, $embed);

            $phpxBuild = $root . '/phpx-build';
            $phpxArchive = $phpxBuild . '/lib/libphpx.a';
            if (!is_file($phpxArchive)) {
                ($this->output)('Building static PHPX runtime');
                $this->run([
                    'cmake',
                    '-S', $this->phpxSourceDirectory . '/sapi-static',
                    '-B', $phpxBuild,
                    '-DCMAKE_BUILD_TYPE=Release',
                    '-DPHPX_ROOT=' . $this->phpxSourceDirectory,
                    '-DPHPX_PHP_PREFIX=' . $prefix,
                ], $root);
                $this->run([
                    'cmake', '--build', $phpxBuild, '--parallel', (string) max(1, $jobs),
                ], $root);
            }
            if (!is_executable($php) || !is_file($phpxArchive)) {
                throw new \RuntimeException('Private SAPI runtime build did not produce PHP CLI and libphpx.a');
            }
            $enabledExtensions = $this->detectEnabledExtensions($php);
            $missing = array_values(array_diff($requiredExtensions, $enabledExtensions));
            if ($missing !== []) {
                throw new \RuntimeException('Private PHP runtime is missing required extensions after build: ' . implode(', ', $missing));
            }
            AtomicFile::write($root . '/runtime.json', json_encode([
                'version' => $sourceVersion,
                'source' => $source,
                'compatibility' => $compatibility,
                'requested_extensions' => $requiredExtensions,
                'enabled_extensions' => $enabledExtensions,
                'configure_options' => $options,
                'sapis' => $runtimeTargets,
                'zts' => $zts,
                'debug' => $debug,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
            return new SapiPhpBuild(
                $source,
                $build,
                $prefix,
                $phpxArchive,
                $sapiArchives,
                $sourceVersion,
                $enabledExtensions,
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param list<string> $requiredExtensions */
    private function findCompatibleRuntime(
        string $cacheDirectory,
        string $sourceVersion,
        string $compatibility,
        array $requiredExtensions,
    ): ?SapiPhpBuild {
        $matches = [];
        foreach (glob($cacheDirectory . '/php-' . $sourceVersion . '-*') ?: [] as $root) {
            $manifest = $root . '/runtime.json';
            if (!is_file($manifest)) {
                continue;
            }
            $metadata = json_decode((string) file_get_contents($manifest), true);
            if (!is_array($metadata) || ($metadata['compatibility'] ?? null) !== $compatibility) {
                continue;
            }
            if (!is_array($metadata['requested_extensions'] ?? null)
                || SapiExtensionRequirements::merge($metadata['requested_extensions']) !== $requiredExtensions
            ) {
                continue;
            }
            $enabled = SapiExtensionRequirements::merge(
                is_array($metadata['enabled_extensions'] ?? null) ? $metadata['enabled_extensions'] : [],
            );
            if (array_diff($requiredExtensions, $enabled) !== []) {
                continue;
            }
            $runtime = $this->runtimeFromRoot($root, $sourceVersion, $enabled, $requiredExtensions);
            if ($runtime !== null) {
                $matches[] = [$runtime, count($enabled), filemtime($manifest) ?: 0];
            }
        }
        usort($matches, static fn (array $left, array $right): int => $left[1] <=> $right[1] ?: $right[2] <=> $left[2]);
        return $matches[0][0] ?? null;
    }

    /** @param list<string> $enabledExtensions */
    private function runtimeFromRoot(
        string $root,
        string $version,
        array $enabledExtensions,
        array $requiredExtensions,
    ): ?SapiPhpBuild {
        $build = $root . '/build';
        $prefix = $root . '/install';
        $phpxArchive = $root . '/phpx-build/lib/libphpx.a';
        $metadata = json_decode((string) @file_get_contents($root . '/runtime.json'), true);
        $targets = is_array($metadata) && is_array($metadata['sapis'] ?? null)
            ? $metadata['sapis']
            : ['cli', 'fpm'];
        $archives = [];
        foreach ($targets as $target) {
            if ($target === 'embed') {
                $archives['embed'] = $prefix . '/lib/libphp.a';
            } elseif ($target === 'cli' || $target === 'fpm') {
                $archives[$target] = $root . '/lib/libphp-' . $target . '.a';
            }
        }
        if (!is_executable($prefix . '/bin/php')
            || !is_file($phpxArchive)
            || array_diff($requiredExtensions, $enabledExtensions) !== []
            || array_filter($archives, static fn (string $archive): bool => !is_file($archive)) !== []
            || !is_file($build . '/Makefile')
        ) {
            return null;
        }
        $source = is_array($metadata) && is_string($metadata['source'] ?? null)
            ? $metadata['source']
            : OfficialPhpSource::defaultCacheDirectory() . '/src/php-' . $version;
        if (!is_dir($source)) {
            return null;
        }
        return new SapiPhpBuild(
            $source,
            $build,
            $prefix,
            $phpxArchive,
            $archives,
            $version,
            $enabledExtensions,
        );
    }

    /** @return list<string> */
    private function detectEnabledExtensions(string $php): array
    {
        $process = proc_open(
            [$php, '-n', '-r', 'echo json_encode(get_loaded_extensions());'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException("Unable to inspect private PHP extensions: {$php}");
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $decoded = is_string($stdout) ? json_decode($stdout, true) : null;
        if ($status !== 0 || !is_array($decoded)) {
            throw new \RuntimeException("Unable to inspect private PHP extensions: {$php}" . (is_string($stderr) && trim($stderr) !== '' ? PHP_EOL . trim($stderr) : ''));
        }
        return SapiExtensionRequirements::merge(array_values(array_filter($decoded, 'is_string')));
    }

    /** @return array<string, string> */
    private function buildSapiArchives(
        string $build,
        string $root,
        int $jobs,
        array $targets,
        string $embedArchive,
    ): array
    {
        $libraryDirectory = $root . '/lib';
        $this->mkdir($libraryDirectory);
        $archives = [];
        foreach (array_intersect($targets, ['cli', 'fpm']) as $target) {
            $archives[$target] = $libraryDirectory . '/libphp-' . $target . '.a';
        }
        if (in_array('embed', $targets, true)) {
            $archives['embed'] = $embedArchive;
        }
        if (array_filter($archives, static fn (string $archive): bool => !is_file($archive)) === []) {
            return $archives;
        }
        // The normal PHP build creates every core and SAPI object. Archive
        // them once, excluding the two entry objects that TypePHP patches to
        // accept embedded primary scripts.
        $this->run(['make', '-j' . max(1, $jobs)], $build);
        $variables = $this->readMakeVariables($build . '/Makefile', [
            'PHP_GLOBAL_OBJS', 'PHP_CLI_OBJS', 'PHP_FPM_OBJS',
        ]);
        foreach ([
            'cli' => ['PHP_CLI_OBJS', 'sapi/cli/php_cli.lo'],
            'fpm' => ['PHP_FPM_OBJS', 'sapi/fpm/fpm/fpm_main.lo'],
        ] as $target => [$variable, $entryObject]) {
            if (!isset($archives[$target])) {
                continue;
            }
            if (is_file($archives[$target])) {
                continue;
            }
            $objects = $this->objectFiles(
                ($variables['PHP_GLOBAL_OBJS'] ?? '') . ' ' . ($variables[$variable] ?? ''),
                $entryObject,
                $build,
            );
            ($this->output)('Caching PHP ' . strtoupper($target) . ' runtime: ' . $archives[$target]);
            $temporary = $archives[$target] . '.part-' . bin2hex(random_bytes(6));
            $responseFile = $archives[$target] . '.objects.rsp';
            AtomicFile::write(
                $responseFile,
                implode(PHP_EOL, array_map($this->quoteResponseFileArgument(...), $objects)) . PHP_EOL,
            );
            try {
                $this->run($this->archiveCommand($temporary, '@' . $responseFile), $build);
            } catch (\RuntimeException) {
                // GNU ar and llvm-ar accept @response files. Keep a fallback
                // for older platform archivers while still abbreviating the
                // displayed command so hundreds of object paths are not
                // written to the user's terminal.
                @unlink($temporary);
                ($this->output)('Archiver response files are unavailable; retrying with direct arguments');
                $this->run($this->archiveCommand($temporary, ...$objects), $build);
            }
            if (!rename($temporary, $archives[$target])) {
                @unlink($temporary);
                throw new \RuntimeException('Unable to store PHP SAPI archive: ' . $archives[$target]);
            }
        }
        if (isset($archives['embed']) && !is_file($archives['embed'])) {
            throw new \RuntimeException('PHP Embed static archive was not installed: ' . $archives['embed']);
        }
        return $archives;
    }

    /** @return list<string> */
    private function objectFiles(string $value, string $excluded, string $build): array
    {
        $objects = [];
        foreach (preg_split('/\s+/', trim($value)) ?: [] as $object) {
            if ($object === '' || $object === $excluded) {
                continue;
            }
            $object = preg_replace('/\.lo$/', '.o', $object) ?? $object;
            $path = str_starts_with($object, '/') ? $object : $build . '/' . $object;
            if (!is_file($path)) {
                throw new \RuntimeException("PHP build object does not exist: {$path}");
            }
            $objects[] = $path;
        }
        if ($objects === []) {
            throw new \RuntimeException('PHP build did not publish objects for the requested SAPI archive');
        }
        return $objects;
    }

    /** @param list<string> $names @return array<string, string> */
    private function readMakeVariables(string $makefile, array $names): array
    {
        $contents = (string) file_get_contents($makefile);
        $values = [];
        foreach ($names as $name) {
            if (preg_match('/^' . preg_quote($name, '/') . '[ \t]*=[ \t]*(.*)$/m', $contents, $match) === 1) {
                $values[$name] = trim($match[1]);
            }
        }
        return $values;
    }

    /** @param list<string> $command */
    private function run(array $command, string $directory): void
    {
        ($this->output)('$ ' . $this->displayCommand($command));
        $logPath = rtrim($directory, '/\\') . '/.typephp-build.log';
        $log = fopen($logPath, 'ab');
        if ($log === false) {
            throw new \RuntimeException("Unable to open SAPI build log: {$logPath}");
        }
        fwrite($log, PHP_EOL . '$ ' . implode(' ', array_map('escapeshellarg', $command)) . PHP_EOL);

        $pendingObjects = $this->pendingMakeObjects($command, $directory);
        $pendingObjectSet = array_fill_keys($pendingObjects, true);
        $isCmakeBuild = basename($command[0]) === 'cmake' && in_array('--build', $command, true);
        $progressTotal = $pendingObjects !== [] ? count($pendingObjects) : ($isCmakeBuild ? 100 : 0);
        $progressLabel = $pendingObjects !== [] ? 'Building PHP' : 'Building PHPX';
        $progressStage = $progressLabel;
        $completed = 0;
        $completedObjects = [];
        if ($progressTotal !== 0 && $this->progress !== null) {
            ($this->progress)(0, $progressTotal, $progressLabel, false);
        }

        $process = proc_open($command, [
            STDIN,
            ['pipe', 'w'],
            ['pipe', 'w'],
        ], $pipes, $directory, $this->processEnvironment());
        if (!is_resource($process)) {
            fclose($log);
            throw new \RuntimeException('Unable to start command: ' . implode(' ', $command));
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $lineBuffers = [1 => '', 2 => ''];
        $startedAt = microtime(true);
        $lastHeartbeatAt = $startedAt;
        $exitStatus = -1;
        while (true) {
            $read = [];
            foreach ([1, 2] as $index) {
                if (!feof($pipes[$index])) {
                    $read[] = $pipes[$index];
                }
            }
            if ($read !== []) {
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 1, 0);
                foreach ($read as $stream) {
                    $index = $stream === $pipes[1] ? 1 : 2;
                    while (($chunk = fread($stream, 8192)) !== false && $chunk !== '') {
                        fwrite($log, $chunk);
                        $lineBuffers[$index] .= $chunk;
                        $this->consumeProgressLines(
                            $lineBuffers[$index],
                            $pendingObjectSet,
                            $completedObjects,
                            $completed,
                            $progressTotal,
                            $progressLabel,
                            $progressStage,
                            $isCmakeBuild,
                        );
                    }
                }
            } else {
                usleep(100_000);
            }

            $processStatus = proc_get_status($process);
            $now = microtime(true);
            if ($processStatus['running'] && $now - $lastHeartbeatAt >= 5.0) {
                if ($progressTotal !== 0 && $this->progress !== null) {
                    ($this->progress)(
                        $this->liveProgressValue($completed, $progressTotal),
                        $progressTotal,
                        sprintf('%s (%ds)', $progressStage, (int) ($now - $startedAt)),
                        false,
                    );
                } else {
                    ($this->output)(sprintf(
                        'Build command still running (%ds); output: %s',
                        (int) ($now - $startedAt),
                        $logPath,
                    ));
                }
                $lastHeartbeatAt = $now;
            }
            if (!$processStatus['running']) {
                $exitStatus = $processStatus['exitcode'];
                break;
            }
        }
        foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if (is_string($chunk) && $chunk !== '') {
                fwrite($log, $chunk);
                $lineBuffers[$index] .= $chunk;
            }
            $this->consumeProgressLines(
                $lineBuffers[$index],
                $pendingObjectSet,
                $completedObjects,
                $completed,
                $progressTotal,
                $progressLabel,
                $progressStage,
                $isCmakeBuild,
                true,
            );
            fclose($pipes[$index]);
        }
        $closeStatus = proc_close($process);
        if ($exitStatus < 0) {
            $exitStatus = $closeStatus;
        }
        if ($progressTotal !== 0 && $this->progress !== null) {
            if ($exitStatus === 0) {
                $completed = $progressTotal;
            }
            ($this->progress)(
                $completed,
                $progressTotal,
                $exitStatus === 0 ? $progressLabel . ' complete' : $progressLabel . ' failed',
                true,
            );
        }
        fclose($log);
        if ($exitStatus !== 0) {
            $contents = (string) @file_get_contents($logPath);
            $lines = preg_split('/\R/', trim($contents)) ?: [];
            $tail = implode(PHP_EOL, array_slice($lines, -40));
            throw new \RuntimeException('Command failed: ' . implode(' ', $command) . PHP_EOL . 'Build log: ' . $logPath . ($tail === '' ? '' : PHP_EOL . $tail));
        }
    }

    private function quoteResponseFileArgument(string $argument): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $argument) . '"';
    }

    /** @return list<string> */
    private function archiveCommand(string $archive, string ...$objects): array
    {
        $toolchain = $this->activeToolchain ?? $this->toolchain;
        if ($toolchain === null) {
            throw new \LogicException('PHP builder toolchain was not initialized');
        }
        if ($toolchain->archiverStyle === CompilerToolchain::ARCHIVER_MSVC) {
            return [$toolchain->archiver, '/NOLOGO', '/OUT:' . $archive, ...$objects];
        }
        return [$toolchain->archiver, 'rcs', $archive, ...$objects];
    }

    /** @param list<string> $command */
    private function displayCommand(array $command): string
    {
        $program = strtolower(preg_replace('/\.exe$/i', '', basename(str_replace('\\', '/', $command[0]))) ?? '');
        if ((str_ends_with($program, 'ar') || $program === 'lib') && count($command) > 8) {
            $objectCount = count($command) - 3;
            return implode(' ', array_map('escapeshellarg', array_slice($command, 0, 3)))
                . ' ' . escapeshellarg("@<{$objectCount} object files>");
        }
        return implode(' ', array_map('escapeshellarg', $command));
    }

    /** @param list<string> $command @return list<string> */
    private function pendingMakeObjects(array $command, string $directory): array
    {
        if (basename($command[0]) !== 'make'
            || array_filter(array_slice($command, 1), static fn (string $arg): bool => !str_starts_with($arg, '-')) !== []
        ) {
            return [];
        }
        $process = proc_open(
            [$command[0], '-n', '-j1'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
            $this->processEnvironment(),
        );
        if (!is_resource($process)) {
            return [];
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            return [];
        }
        return $this->extractObjectTargets((string) $output . PHP_EOL . (string) $error);
    }

    /** @return list<string> */
    private function extractObjectTargets(string $output): array
    {
        preg_match_all(
            '/(?:^|\s)-o\s+(?:\'([^\']+\.lo)\'|"([^"]+\.lo)"|([^\s\'";]+\.lo))(?=\s|$)/m',
            $output,
            $matches,
            PREG_SET_ORDER,
        );
        $targets = [];
        foreach ($matches as $match) {
            $target = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : $match[3]);
            $targets[$target] = true;
        }
        return array_keys($targets);
    }

    /**
     * @param array<string, true> $pendingObjects
     * @param array<string, true> $completedObjects
     */
    private function consumeProgressLines(
        string &$buffer,
        array $pendingObjects,
        array &$completedObjects,
        int &$completed,
        int $total,
        string $label,
        string &$stage,
        bool $isCmakeBuild,
        bool $flush = false,
    ): void {
        $lines = preg_split('/\R/', $buffer);
        if ($lines === false) {
            return;
        }
        $buffer = $flush ? '' : (array_pop($lines) ?? '');
        foreach ($lines as $line) {
            $nextStage = $this->detectBuildStage($line);
            if ($nextStage !== null && $nextStage !== $stage) {
                $stage = $nextStage;
                if ($this->progress !== null && $total !== 0) {
                    ($this->progress)(
                        $this->liveProgressValue($completed, $total),
                        $total,
                        $stage,
                        false,
                    );
                }
            }
            if ($pendingObjects !== []) {
                foreach ($this->extractObjectTargets($line) as $target) {
                    if (!isset($pendingObjects[$target]) || isset($completedObjects[$target])) {
                        continue;
                    }
                    $completedObjects[$target] = true;
                    ++$completed;
                    if ($this->progress !== null) {
                        if ($completed >= $total) {
                            $stage = 'Finishing PHP compilation';
                            ($this->progress)(
                                $this->liveProgressValue($completed, $total),
                                $total,
                                $stage,
                                false,
                            );
                        } else {
                            ($this->progress)(
                                $this->liveProgressValue($completed, $total),
                                $total,
                                $target,
                                false,
                            );
                        }
                    }
                }
            } elseif ($isCmakeBuild && preg_match('/\[\s*(\d{1,3})%\]/', $line, $match) === 1) {
                $next = min($total, (int) $match[1]);
                if ($next > $completed) {
                    $completed = $next;
                    if ($this->progress !== null) {
                        ($this->progress)($this->liveProgressValue($completed, $total), $total, $label, false);
                    }
                }
            }
        }
    }

    private function liveProgressValue(int $completed, int $total): int
    {
        // Progressbar rounds to the nearest integer percentage, so total - 1
        // can still render as 100% for a large PHP build (633/634 = 99.84%).
        // Reserve enough of the tail to keep every live update below 99.5%;
        // the successful process exit is the only event allowed to show 100%.
        $maximumLive = max(0, (int) ceil($total * 0.995) - 1);
        return min($completed, $maximumLive);
    }

    private function detectBuildStage(string $line): ?string
    {
        if (str_contains($line, '--mode=link')) {
            if (preg_match('/(?:^|\s)-o\s+[^\s]*opcache(?:\.la|\.so)(?:\s|$)/', $line) === 1) {
                return 'Linking OPcache';
            }
            if (preg_match('#(?:^|\s)-o\s+(?:\'|")?sapi/cli/php(?:\'|"|\s|$)#', $line) === 1) {
                return 'Linking PHP CLI';
            }
            if (preg_match('#(?:^|\s)-o\s+(?:\'|")?sapi/fpm/php-fpm(?:\'|"|\s|$)#', $line) === 1) {
                return 'Linking PHP FPM';
            }
            if (preg_match('#(?:^|\s)-o\s+(?:\'|")?(?:libs/)?libphp(?:\.la|\.a)(?:\'|"|\s|$)#', $line) === 1) {
                return 'Linking PHP Embed';
            }
        }
        if (str_starts_with($line, 'Generating phar.php')) {
            return 'Generating phar.php';
        }
        if (str_starts_with($line, 'Generating phar.phar')) {
            return 'Generating phar.phar';
        }
        return null;
    }

    /** @return array<string, string>|null */
    private function processEnvironment(): ?array
    {
        $toolchain = $this->activeToolchain ?? $this->toolchain;
        if ($toolchain === null) {
            return null;
        }
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['CC'] = $toolchain->cCompiler;
        $environment['CXX'] = $toolchain->cxxCompiler;
        $environment['AR'] = $toolchain->archiver;
        return $environment;
    }

    private function mkdir(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create SAPI build directory: {$directory}");
        }
    }
}

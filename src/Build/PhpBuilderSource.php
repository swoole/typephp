<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

final class PhpBuilderSource
{
    private readonly \Closure $output;

    /** @param callable(string):void $output */
    public function __construct(
        private readonly string $cacheDirectory,
        callable $output,
        private readonly ?string $proxy = null,
    ) {
        $this->output = \Closure::fromCallable($output);
    }

    /**
     * @param list<string> $extensions
     * @return array{source:string,external:array<string,string>}
     */
    public function prepare(string $officialSource, array $extensions): array
    {
        $external = [];
        $sources = [];
        $pecl = new PeclExtensionSource($this->cacheDirectory, $this->output, $this->proxy);
        foreach (SapiExtensionConfiguration::withoutCoreExtensions(
            SapiExtensionRequirements::merge($extensions),
        ) as $extension) {
            $directoryName = str_replace('-', '_', $extension);
            if (is_dir($officialSource . '/ext/' . $directoryName)) {
                continue;
            }
            $release = $pecl->prepare($extension);
            $external[$extension] = $release['version'];
            $sources[$extension] = $release['directory'];
        }
        if ($external === []) {
            return ['source' => $officialSource, 'external' => []];
        }

        ksort($external, SORT_STRING);
        $version = OfficialPhpSource::version($officialSource);
        $fingerprint = substr(hash('sha256', json_encode($external, JSON_THROW_ON_ERROR)), 0, 16);
        $source = $this->cacheDirectory . '/php-builder-src/php-' . $version . '-' . $fingerprint;
        $manifest = $source . '/.typephp-extensions.json';
        $metadata = json_encode($external, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . PHP_EOL;
        if (is_file($source . '/configure') && @file_get_contents($manifest) === $metadata) {
            return ['source' => $source, 'external' => $external];
        }
        if (is_dir($source)) {
            throw new \RuntimeException("Incomplete PHP builder source cache must be removed: {$source}");
        }

        $parent = dirname($source);
        $this->mkdir($parent);
        $temporary = $source . '.part-' . bin2hex(random_bytes(6));
        $this->mkdir($temporary);
        ($this->output)('Preparing php-src with external extensions: ' . implode(', ', array_keys($external)));
        $this->run(['cp', '-a', rtrim($officialSource, '/\\') . '/.', $temporary]);
        foreach ($sources as $extension => $extensionSource) {
            $target = $temporary . '/ext/' . str_replace('-', '_', $extension);
            $this->mkdir($target);
            $this->run(['cp', '-a', rtrim($extensionSource, '/\\') . '/.', $target]);
        }
        $this->run(['sh', $temporary . '/buildconf', '--force'], $temporary);
        AtomicFile::write($temporary . '/.typephp-extensions.json', $metadata);
        if (!rename($temporary, $source)) {
            throw new \RuntimeException("Unable to publish PHP builder source tree: {$source}");
        }
        return ['source' => $source, 'external' => $external];
    }

    /** @param list<string> $command */
    private function run(array $command, ?string $directory = null): void
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $directory);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new \RuntimeException('Command failed: ' . implode(' ', $command));
        }
    }

    private function mkdir(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create PHP builder source directory: {$directory}");
        }
    }
}

<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

final class SapiExtensionConfiguration
{
    /** Extensions compiled unconditionally by php-src. */
    private const array CORE_EXTENSIONS = [
        'core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard',
    ];

    /**
     * @param list<string> $extensions
     * @return list<string>
     */
    public static function configureOptions(string $phpSourceDirectory, array $extensions): array
    {
        $options = [];
        $pending = SapiExtensionRequirements::merge($extensions);
        $visited = [];
        for ($index = 0; $index < count($pending); $index++) {
            $extension = $pending[$index];
            if (isset($visited[$extension])) {
                continue;
            }
            $visited[$extension] = true;
            if (in_array($extension, self::CORE_EXTENSIONS, true)) {
                continue;
            }
            if ($extension === 'opcache') {
                $options[] = '--enable-opcache';
                continue;
            }
            $directory = $phpSourceDirectory . '/ext/' . str_replace('-', '_', $extension);
            if (!is_dir($directory)) {
                throw new \RuntimeException("PHP extension `{$extension}` is not bundled with php-src and cannot be linked into the SAPI runtime");
            }
            $configuration = self::readConfiguration($directory);
            $option = self::findConfigureOption($configuration, $extension);
            // An extension directory without a configure switch is part of the
            // core build and needs no extra argument.
            if ($option !== null) {
                $options[] = $option;
            }
            foreach (self::requiredDependencies($configuration, $extension) as $dependency) {
                $pending[] = $dependency;
            }
        }
        return array_values(array_unique($options));
    }

    private static function readConfiguration(string $directory): string
    {
        $contents = '';
        foreach (array_unique([
            $directory . '/config0.m4',
            $directory . '/config.m4',
            ...(glob($directory . '/config*.m4') ?: []),
        ]) as $file) {
            if (!is_file($file)) {
                continue;
            }
            // Ignore m4 comments so disabled declarations cannot enable extensions.
            $contents .= (preg_replace('/^\s*(?:dnl\b|#).*$/m', '', (string) file_get_contents($file)) ?? '') . "\n";
        }
        return $contents;
    }

    private static function findConfigureOption(string $configuration, string $extension): ?string
    {
        preg_match_all(
            '/PHP_ARG_(WITH|ENABLE)\s*\(\s*\[?([A-Za-z0-9_-]+)\]?/i',
            $configuration,
            $matches,
            PREG_SET_ORDER,
        );
        foreach ($matches as $match) {
            if (SapiExtensionRequirements::normalize($match[2]) !== $extension) {
                continue;
            }
            return ($match[1] === 'WITH' ? '--with-' : '--enable-') . $match[2];
        }
        return null;
    }

    /** @return list<string> */
    private static function requiredDependencies(string $configuration, string $extension): array
    {
        preg_match_all(
            '/PHP_ADD_EXTENSION_DEP\s*\(\s*\[?([A-Za-z0-9_-]+)\]?\s*,\s*\[?([A-Za-z0-9_-]+)\]?\s*(?:,\s*\[?(true|false)\]?\s*)?\)/i',
            $configuration,
            $matches,
            PREG_SET_ORDER,
        );
        $dependencies = [];
        foreach ($matches as $match) {
            if (SapiExtensionRequirements::normalize($match[1]) === $extension
                && strtolower($match[3] ?? 'false') !== 'true'
            ) {
                $dependencies[] = SapiExtensionRequirements::normalize($match[2]);
            }
        }
        return $dependencies;
    }
}

<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

use ReflectionClass;
use TypePhp\Exception\SyntaxError;

final class ProviderBootstrap
{
    private const ENTRY_KEYS = ['file', 'class'];

    /** @param array<int|string, mixed> $entries */
    public static function install(array $entries, string $projectDir, string $manifestFile): void
    {
        if ($entries === []) {
            return;
        }
        if (!array_is_list($entries)) {
            throw new SyntaxError(
                'compile-time-attributes.providers must be a list in ' . $manifestFile,
            );
        }
        $resolved = [];
        foreach ($entries as $index => $entry) {
            $resolved[] = self::resolveEntry($entry, $index, $projectDir, $manifestFile);
        }
        usort($resolved, static fn (array $a, array $b): int => strcmp($a['file'], $b['file']));

        $hash = hash_init('sha256');
        foreach ($resolved as $record) {
            foreach (self::providersIn($record['file'], $record['class'], $manifestFile) as $definition) {
                ProviderTable::register($definition);
                hash_update($hash, implode("\0", [
                    $definition->file,
                    hash_file('sha256', $definition->file) ?: '',
                    $definition->attribute,
                    $definition->target,
                    $definition->providerClass,
                ]) . "\n");
            }
        }
        ProviderTable::setFingerprint(hash_final($hash));
    }

    /**
     * @param mixed $entry
     * @return array{file: string, class: ?string}
     */
    private static function resolveEntry(mixed $entry, int|string $index, string $projectDir, string $manifestFile): array
    {
        if (!is_array($entry)) {
            throw new SyntaxError(
                'compile-time-attributes.providers[' . $index . '] must be a map with a `file` key in '
                . $manifestFile,
            );
        }
        $unknown = array_diff(array_keys($entry), self::ENTRY_KEYS);
        if ($unknown !== []) {
            throw new SyntaxError(
                'compile-time-attributes.providers[' . $index . '] has unknown key(s) `'
                . implode('`, `', $unknown) . '` in ' . $manifestFile,
            );
        }
        $file = $entry['file'] ?? null;
        if (!is_string($file) || $file === '') {
            throw new SyntaxError(
                'compile-time-attributes.providers[' . $index . '].file must be a non-empty string in '
                . $manifestFile,
            );
        }
        $class = $entry['class'] ?? null;
        if ($class !== null && (!is_string($class) || $class === '')) {
            throw new SyntaxError(
                'compile-time-attributes.providers[' . $index . '].class must be a non-empty string in '
                . $manifestFile,
            );
        }
        $path = self::isAbsolute($file) ? $file : $projectDir . '/' . $file;
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new SyntaxError(
                'compile-time attribute provider file not found: ' . $file . ' (resolved from '
                . $manifestFile . ')',
            );
        }

        return ['file' => $real, 'class' => $class];
    }

    /** @return list<ProviderDefinition> */
    private static function providersIn(string $file, ?string $class, string $manifestFile): array
    {
        require_once $file;
        if ($class !== null) {
            $candidates = [$class];
        } else {
            $candidates = array_values(array_filter(
                get_declared_classes(),
                static fn (string $name): bool => (new ReflectionClass($name))->getFileName() === $file,
            ));
        }

        $definitions = [];
        foreach ($candidates as $candidate) {
            if (!class_exists($candidate)) {
                throw new SyntaxError(
                    'compile-time attribute provider class ' . $candidate . ' was not declared by ' . $file,
                );
            }
            $reflection = new ReflectionClass($candidate);
            $attributes = $reflection->getAttributes(ProviderFor::class);
            if ($attributes === []) {
                throw new SyntaxError(
                    'compile-time attribute provider class ' . $candidate . ' is missing #[ProviderFor] in '
                    . $manifestFile,
                );
            }
            if (count($attributes) > 1) {
                throw new SyntaxError(
                    'compile-time attribute provider class ' . $candidate . ' declares more than one #[ProviderFor]',
                );
            }
            if (!$reflection->isInstantiable()) {
                throw new SyntaxError(
                    'compile-time attribute provider class ' . $candidate . ' must be instantiable',
                );
            }
            if (!$reflection->hasMethod('compile') || $reflection->getMethod('compile')->getNumberOfParameters() < 1) {
                throw new SyntaxError(
                    'compile-time attribute provider class ' . $candidate
                    . ' must declare compile(Generator $g): void',
                );
            }
            $providerFor = $attributes[0]->newInstance();
            if (!in_array($providerFor->target, ProviderDefinition::TARGETS, true)) {
                throw new SyntaxError(sprintf(
                    'compile-time attribute provider class %s declares unknown target `%s`; expected one of %s',
                    $candidate,
                    $providerFor->target,
                    implode(', ', ProviderDefinition::TARGETS),
                ));
            }
            $attribute = ltrim($providerFor->attribute, '\\');
            if ($attribute === '') {
                throw new SyntaxError(
                    'compile-time attribute provider class ' . $candidate . ' declares an empty annotation name',
                );
            }
            $definitions[] = new ProviderDefinition(
                $attribute,
                $providerFor->target,
                $reflection->newInstance(),
                $candidate,
                $file,
            );
        }

        if ($definitions === []) {
            throw new SyntaxError(
                'compile-time attribute provider file ' . $file . ' declares no class with #[ProviderFor]',
            );
        }

        return $definitions;
    }

    private static function isAbsolute(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:/', $path) === 1);
    }
}

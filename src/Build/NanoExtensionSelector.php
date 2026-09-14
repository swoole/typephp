<?php

namespace TypePhp\Build;

use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use TypePhp\Analysis\CompilationStatistics;
use TypePhp\Type;

/**
 * Converts generic compiler usage statistics into extension-level Nano needs.
 *
 * Source ownership remains php-nano metadata. This module only identifies
 * extension names and computes their dependency closure.
 */
final class NanoExtensionSelector
{
    /** @var array<string, string> */
    private const array EXTENSION_NAMES = [
        'standard' => 'standard',
        'date' => 'date',
        'hash' => 'hash',
        'json' => 'json',
        'pcre' => 'pcre',
        'random' => 'random',
        'reflection' => 'reflection',
        'spl' => 'spl',
        'filter' => 'filter',
        'bcmath' => 'bcmath',
    ];

    /** @var array<string, list<string>> */
    private const array DEPENDENCIES = [
        'spl' => ['json'],
    ];

    /** @var array<string, string> */
    private const array FUNCTION_OVERRIDES = [
        'std::bigint' => 'bcmath',
        'std::bigfloat' => 'bcmath',
        'std::decimal' => 'bcmath',
    ];

    /** @var array<string, string> */
    private const array STANDARD_DIRECT_FEATURES = [
        'count' => 'standard.array',
        'sizeof' => 'standard.array',
        'in_array' => 'standard.array',
        'array_search' => 'standard.array',
        'array_key_first' => 'standard.array',
        'array_key_last' => 'standard.array',
        'strlen' => 'standard.string',
        'trim' => 'standard.string',
        'ltrim' => 'standard.string',
        'rtrim' => 'standard.string',
        'strtolower' => 'standard.string',
        'strtoupper' => 'standard.string',
        'lcfirst' => 'standard.string',
        'ucfirst' => 'standard.string',
        'ucwords' => 'standard.string',
        'explode' => 'standard.string',
        'implode' => 'standard.string',
        'strpos' => 'standard.string',
        'stripos' => 'standard.string',
        'strrpos' => 'standard.string',
        'strstr' => 'standard.string',
        'stristr' => 'standard.string',
        'substr' => 'standard.string',
        'dirname' => 'standard.string',
        'basename' => 'standard.string',
        'is_dir' => 'standard.filesystem',
        'is_file' => 'standard.filesystem',
        'file_exists' => 'standard.filesystem',
        'realpath' => 'standard.filesystem',
        'round' => 'standard.math',
        'md5' => 'standard.hash',
        'sha1' => 'standard.hash',
        'version_compare' => 'standard.misc',
        'print_r' => 'standard.misc',
        'uniqid' => 'standard.misc',
        'parse_str' => 'standard.misc',
        'class_exists' => 'standard.core',
        'interface_exists' => 'standard.core',
        'trait_exists' => 'standard.core',
        'enum_exists' => 'standard.core',
        'function_exists' => 'standard.core',
        'method_exists' => 'standard.core',
        'property_exists' => 'standard.core',
        'is_a' => 'standard.core',
        'is_subclass_of' => 'standard.core',
        'defined' => 'standard.core',
        'define' => 'standard.core',
        'get_parent_class' => 'standard.core',
    ];

    /** @var array<string, string> */
    private const array STANDARD_FUNCTION_FEATURES = [
        'compact' => 'standard.array',
        'current' => 'standard.array',
        'end' => 'standard.array',
        'key' => 'standard.array',
        'max' => 'standard.array',
        'min' => 'standard.array',
        'next' => 'standard.array',
        'pos' => 'standard.array',
        'prev' => 'standard.array',
        'range' => 'standard.array',
        'reset' => 'standard.array',
        'sort' => 'standard.array',
        'rsort' => 'standard.array',
        'asort' => 'standard.array',
        'arsort' => 'standard.array',
        'ksort' => 'standard.array',
        'krsort' => 'standard.array',
        'usort' => 'standard.array',
        'uasort' => 'standard.array',
        'uksort' => 'standard.array',
        'base64_encode' => 'standard.encoding',
        'base64_decode' => 'standard.encoding',
        'htmlspecialchars' => 'standard.encoding',
        'htmlspecialchars_decode' => 'standard.encoding',
        'html_entity_decode' => 'standard.encoding',
        'htmlentities' => 'standard.encoding',
        'get_html_translation_table' => 'standard.encoding',
        'parse_url' => 'standard.encoding',
        'urlencode' => 'standard.encoding',
        'urldecode' => 'standard.encoding',
        'rawurlencode' => 'standard.encoding',
        'rawurldecode' => 'standard.encoding',
        'chdir' => 'standard.filesystem',
        'chroot' => 'standard.filesystem',
        'closedir' => 'standard.filesystem',
        'copy' => 'standard.filesystem',
        'disk_free_space' => 'standard.filesystem',
        'disk_total_space' => 'standard.filesystem',
        'getcwd' => 'standard.filesystem',
        'glob' => 'standard.filesystem',
        'mkdir' => 'standard.filesystem',
        'opendir' => 'standard.filesystem',
        'pathinfo' => 'standard.filesystem',
        'readdir' => 'standard.filesystem',
        'rename' => 'standard.filesystem',
        'rewinddir' => 'standard.filesystem',
        'rmdir' => 'standard.filesystem',
        'scandir' => 'standard.filesystem',
        'stat' => 'standard.filesystem',
        'lstat' => 'standard.filesystem',
        'tempnam' => 'standard.filesystem',
        'tmpfile' => 'standard.filesystem',
        'unlink' => 'standard.filesystem',
        'fprintf' => 'standard.format',
        'printf' => 'standard.format',
        'scanf' => 'standard.format',
        'sprintf' => 'standard.format',
        'sscanf' => 'standard.format',
        'vfprintf' => 'standard.format',
        'vprintf' => 'standard.format',
        'vsprintf' => 'standard.format',
        'md5' => 'standard.hash',
        'md5_file' => 'standard.hash',
        'sha1' => 'standard.hash',
        'sha1_file' => 'standard.hash',
        'phpcredits' => 'standard.info',
        'phpinfo' => 'standard.info',
        'phpversion' => 'standard.info',
        'php_ini_loaded_file' => 'standard.info',
        'php_ini_scanned_files' => 'standard.info',
        'php_sapi_name' => 'standard.info',
        'php_uname' => 'standard.info',
        'abs' => 'standard.math',
        'ceil' => 'standard.math',
        'floor' => 'standard.math',
        'fmod' => 'standard.math',
        'fdiv' => 'standard.math',
        'fpow' => 'standard.math',
        'intdiv' => 'standard.math',
        'number_format' => 'standard.math',
        'pi' => 'standard.math',
        'pow' => 'standard.math',
        'sqrt' => 'standard.math',
        'hrtime' => 'standard.misc',
        'microtime' => 'standard.misc',
        'gettimeofday' => 'standard.misc',
        'sleep' => 'standard.misc',
        'usleep' => 'standard.misc',
        'time_nanosleep' => 'standard.misc',
        'time_sleep_until' => 'standard.misc',
        'uniqid' => 'standard.misc',
        'version_compare' => 'standard.misc',
        'bin2hex' => 'standard.string',
        'chr' => 'standard.string',
        'explode' => 'standard.string',
        'hex2bin' => 'standard.string',
        'implode' => 'standard.string',
        'join' => 'standard.string',
        'nl2br' => 'standard.string',
        'ord' => 'standard.string',
        'parse_str' => 'standard.string',
        'strrev' => 'standard.string',
        'wordwrap' => 'standard.string',
        'boolval' => 'standard.type',
        'doubleval' => 'standard.type',
        'floatval' => 'standard.type',
        'get_debug_type' => 'standard.type',
        'gettype' => 'standard.type',
        'intval' => 'standard.type',
        'settype' => 'standard.type',
        'strval' => 'standard.type',
        'debug_zval_dump' => 'standard.var',
        'memory_get_peak_usage' => 'standard.var',
        'memory_get_usage' => 'standard.var',
        'memory_reset_peak_usage' => 'standard.var',
        'print_r' => 'standard.var',
        'serialize' => 'standard.var',
        'unserialize' => 'standard.var',
        'var_dump' => 'standard.var',
        'var_export' => 'standard.var',
        'constant' => 'standard.core',
        'ini_alter' => 'standard.core',
        'ini_get' => 'standard.core',
        'ini_get_all' => 'standard.core',
        'ini_parse_quantity' => 'standard.core',
        'ini_restore' => 'standard.core',
        'ini_set' => 'standard.core',
    ];

    /** @var array<string, string> */
    private const array STANDARD_CLASS_FEATURES = [
        '__php_incomplete_class' => 'standard.var',
        'roundingmode' => 'standard.math',
        'sortdirection' => 'standard.array',
    ];

    /**
     * @param list<string> $availableExtensions
     */
    public function select(
        CompilationStatistics $statistics,
        ?array $availableExtensions = null,
    ): NanoExtensionSelection {
        $availableExtensions ??= array_values(self::EXTENSION_NAMES);
        $available = [];
        foreach ($availableExtensions as $extension) {
            $available[strtolower($extension)] = true;
        }
        ksort($available, SORT_STRING);

        $dynamic = array_keys($statistics->get(CompilationStatistics::DYNAMIC_CAPABILITIES));
        if ($dynamic !== []) {
            // Dynamic calls can only imply the built-in Nano surface. Optional
            // php-src and PIE extensions must still be selected by a statically
            // observed symbol (or, in the future, explicit project metadata).
            // Otherwise adding an extension to php-nano silently bloats every
            // program that contains any dynamic call.
            $builtIn = array_intersect_key($available, self::EXTENSION_NAMES);
            return new NanoExtensionSelection(
                array_keys($builtIn),
                true,
                [],
                array_map(
                    static fn(string $capability): string => "dynamic capability: {$capability}",
                    $dynamic,
                ),
            );
        }

        $selected = [];
        $features = [];
        foreach (array_keys($statistics->get(CompilationStatistics::RUNTIME_FUNCTIONS)) as $function) {
            $owner = self::FUNCTION_OVERRIDES[strtolower($function)]
                ?? $this->functionExtension($function);
            if ($owner === 'standard') {
                $feature = $this->standardFeature($function);
                if ($feature === null) {
                    $selected['standard'] = true;
                } else {
                    $features[$feature] = true;
                }
                continue;
            }
            if ($owner !== null && isset($available[$owner])) {
                $selected[$owner] = true;
            }
        }
        foreach (array_keys($statistics->get(CompilationStatistics::DIRECT_FUNCTIONS)) as $function) {
            $feature = $this->standardFeature($function);
            if ($feature !== null) {
                $features[$feature] = true;
                continue;
            }
            $owner = $this->functionExtension($function);
            if ($owner === 'date') {
                // Common date helpers are coupled to timelib and the DateTime
                // implementation, so date remains one composition unit.
                $selected['date'] = true;
                continue;
            }
            if ($owner === 'standard') {
                $selected['standard'] = true;
                continue;
            }
            if ($owner !== null && isset($available[$owner])) {
                $selected[$owner] = true;
            }
        }
        foreach (array_keys($statistics->get(CompilationStatistics::FUNCTIONS)) as $function) {
            $owner = self::FUNCTION_OVERRIDES[strtolower($function)] ?? null;
            if ($owner !== null && isset($available[$owner])) {
                $selected[$owner] = true;
            }
        }
        foreach (array_keys($statistics->get(CompilationStatistics::CLASSES)) as $class) {
            $owner = $this->classExtension($class);
            if ($owner === 'standard') {
                $feature = self::STANDARD_CLASS_FEATURES[strtolower(ltrim($class, '\\'))] ?? null;
                if ($feature === null) {
                    $selected['standard'] = true;
                } else {
                    $features[$feature] = true;
                }
                continue;
            }
            if ($owner !== null && isset($available[$owner])) {
                $selected[$owner] = true;
            }
        }
        foreach ([Type::BIGINT, Type::BIGFLOAT, Type::DECIMAL] as $type) {
            if ($statistics->has(CompilationStatistics::TYPES, $type)
                && isset($available['bcmath'])
            ) {
                $selected['bcmath'] = true;
            }
        }

        $pending = array_keys($selected);
        while (($extension = array_pop($pending)) !== null) {
            foreach (self::DEPENDENCIES[$extension] ?? [] as $dependency) {
                if (isset($available[$dependency]) && !isset($selected[$dependency])) {
                    $selected[$dependency] = true;
                    $pending[] = $dependency;
                }
            }
        }
        ksort($selected, SORT_STRING);
        ksort($features, SORT_STRING);
        return new NanoExtensionSelection(array_keys($selected), false, array_keys($features));
    }

    private function standardFeature(string $function): ?string
    {
        $function = strtolower($function);
        $feature = self::STANDARD_DIRECT_FEATURES[$function]
            ?? self::STANDARD_FUNCTION_FEATURES[$function]
            ?? null;
        if ($feature !== null) {
            return $feature;
        }
        if (str_starts_with($function, 'array_')) {
            return 'standard.array';
        }
        if (str_starts_with($function, 'str_') || str_starts_with($function, 'substr_')) {
            return 'standard.string';
        }
        if (str_starts_with($function, 'file') || str_starts_with($function, 'stream_')) {
            return 'standard.filesystem';
        }
        if (str_starts_with($function, 'is_')) {
            return in_array($function, ['is_dir', 'is_file', 'is_link', 'is_readable', 'is_writable', 'is_writeable', 'is_executable'], true)
                ? 'standard.filesystem'
                : 'standard.type';
        }
        return null;
    }

    private function functionExtension(string $name): ?string
    {
        if (!function_exists($name)) {
            return null;
        }
        try {
            return $this->normalizeExtension((new ReflectionFunction($name))->getExtensionName());
        } catch (ReflectionException) {
            return null;
        }
    }

    private function classExtension(string $name): ?string
    {
        if (!class_exists($name, false) && !interface_exists($name, false)) {
            return null;
        }
        try {
            return $this->normalizeExtension((new ReflectionClass($name))->getExtensionName());
        } catch (ReflectionException) {
            return null;
        }
    }

    private function normalizeExtension(string|false $extension): ?string
    {
        if (!is_string($extension)) {
            return null;
        }
        $extension = strtolower($extension);
        return preg_match('/^[a-z][a-z0-9_]*$/', $extension) === 1
            ? $extension
            : null;
    }
}

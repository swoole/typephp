<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

use Symfony\Component\Yaml\Yaml;

final readonly class PhpBuilderConfiguration
{
    /**
     * @param list<string> $extensions
     */
    public function __construct(
        public bool $zts,
        public array $extensions,
        public bool $debug = PHP_DEBUG,
    ) {
    }

    public static function fromYaml(mixed $value): self
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException('`php-builder` must be a mapping');
        }
        return self::fromArray($value);
    }

    public static function fromCommandLine(string $value): self
    {
        $value = trim($value);
        if ($value === '') {
            return self::fromYaml([]);
        }

        try {
            $parsed = Yaml::parse(self::semicolonSeparatedYaml($value));
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException(
                'Invalid --php-builder configuration: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
        return self::fromYaml($parsed);
    }

    /** @param array<string, mixed> $config */
    private static function fromArray(array $config): self
    {
        $unknown = array_diff(array_keys($config), ['zts', 'debug', 'extensions']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown `php-builder` option' . (count($unknown) > 1 ? 's' : '')
                . ': ' . implode(', ', array_map(static fn (string $key): string => "`{$key}`", $unknown)),
            );
        }
        $extensions = self::stringList($config['extensions'] ?? [], 'php-builder.extensions', true);
        $extensions = SapiExtensionRequirements::merge($extensions);

        return new self(
            self::boolean($config['zts'] ?? PHP_ZTS, 'zts'),
            $extensions,
            self::boolean($config['debug'] ?? PHP_DEBUG, 'debug'),
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $name, bool $allowEmpty = false): array
    {
        $values = is_string($value) ? preg_split('/\s*,\s*/', trim($value)) : $value;
        if (!is_array($values)) {
            throw new \InvalidArgumentException("`{$name}` must be a string or list");
        }
        $result = [];
        foreach ($values as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                throw new \InvalidArgumentException("Each `{$name}` entry must be a non-empty string");
            }
            $result[] = strtolower(trim($entry));
        }
        if (!$allowEmpty && $result === []) {
            throw new \InvalidArgumentException("`{$name}` must not be empty");
        }
        return $result;
    }

    private static function boolean(mixed $value, string $name): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return (bool) $value;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'on', 'true', 'yes', '1' => true,
                'off', 'false', 'no', '0' => false,
                default => throw new \InvalidArgumentException("`php-builder.{$name}` must be on or off"),
            };
        }
        throw new \InvalidArgumentException("`php-builder.{$name}` must be on or off");
    }

    private static function semicolonSeparatedYaml(string $value): string
    {
        $result = '';
        $quote = null;
        $depth = 0;
        $escaped = false;
        foreach (str_split($value) as $character) {
            if ($escaped) {
                $result .= $character;
                $escaped = false;
                continue;
            }
            if ($character === '\\' && $quote === '"') {
                $result .= $character;
                $escaped = true;
                continue;
            }
            if (($character === '"' || $character === "'") && ($quote === null || $quote === $character)) {
                $quote = $quote === null ? $character : null;
                $result .= $character;
                continue;
            }
            if ($quote === null) {
                if ($character === '[' || $character === '{') {
                    $depth++;
                } elseif ($character === ']' || $character === '}') {
                    $depth--;
                } elseif ($character === ';' && $depth === 0) {
                    $result .= "\n";
                    continue;
                }
            }
            $result .= $character;
        }
        return preg_replace('/\n[ \t]+/', "\n", trim($result)) ?? trim($result);
    }
}

<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

use TypePhp\Exception\SyntaxError;
use TypePhp\Transform\CompileTimeAttributeRegistry;

final class ProviderTable
{
    /** @var array<string, ProviderDefinition> */
    private static array $providers = [];

    private static string $fingerprint = '';

    private static int $generation = 0;

    public static function reset(): void
    {
        if (self::$providers === [] && self::$fingerprint === '') {
            return;
        }
        self::$providers = [];
        self::$fingerprint = '';
        self::$generation++;
        CompileTimeAttributeRegistry::resetProviders();
    }

    public static function register(ProviderDefinition $definition): void
    {
        $key = strtolower(ltrim($definition->attribute, '\\'));
        if (isset(self::$providers[$key])) {
            throw new SyntaxError(sprintf(
                'compile-time attribute #[%s] is claimed by both %s and %s',
                $definition->attribute,
                self::$providers[$key]->providerClass,
                $definition->providerClass,
            ));
        }
        if (CompileTimeAttributeRegistry::hasBuiltin($definition->attribute)) {
            throw new SyntaxError(sprintf(
                'compile-time attribute #[%s] is built in and cannot be provided by %s',
                $definition->attribute,
                $definition->providerClass,
            ));
        }
        self::$providers[$key] = $definition;
        CompileTimeAttributeRegistry::registerProvider($definition->registryDefinition());
    }

    public static function get(string $attribute): ?ProviderDefinition
    {
        return self::$providers[strtolower(ltrim($attribute, '\\'))] ?? null;
    }

    /** @return array<string, ProviderDefinition> */
    public static function all(): array
    {
        return self::$providers;
    }

    public static function active(): bool
    {
        return self::$providers !== [];
    }

    public static function fingerprint(): string
    {
        return self::$fingerprint;
    }

    public static function setFingerprint(string $fingerprint): void
    {
        self::$fingerprint = $fingerprint;
    }

    public static function generation(): int
    {
        return self::$generation;
    }
}

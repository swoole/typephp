<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

final class ExpansionMemo
{
    /** @var array<string, array<string, mixed>> */
    private static array $records = [];

    private static int $generation = -1;

    /** @return array<string, mixed>|null */
    public static function get(string $file, string $fqcn): ?array
    {
        self::sync();

        return self::$records[$file . "\0" . $fqcn] ?? null;
    }

    /** @param array<string, mixed> $record */
    public static function put(string $file, string $fqcn, array $record): void
    {
        self::sync();
        self::$records[$file . "\0" . $fqcn] = $record;
    }

    public static function reset(): void
    {
        self::$records = [];
        self::$generation = ProviderTable::generation();
    }

    private static function sync(): void
    {
        if (self::$generation !== ProviderTable::generation()) {
            self::reset();
        }
    }
}

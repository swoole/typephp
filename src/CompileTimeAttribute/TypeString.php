<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

use PhpParser\Node;

final class TypeString
{
    public static function of(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\Identifier || $type instanceof Node\Name) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . self::of($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map([self::class, 'of'], $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map([self::class, 'of'], $type->types));
        }

        return null;
    }

    public static function ofName(Node $name): string
    {
        $resolved = $name->getAttribute('resolvedName') ?? $name->getAttribute('namespacedName') ?? $name;

        return ltrim($resolved->toString(), '\\');
    }
}

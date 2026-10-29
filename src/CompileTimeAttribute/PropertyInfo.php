<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

final class PropertyInfo
{
    /** @param array<string, bool> $hooks */
    public function __construct(
        public readonly string $name,
        public readonly ?string $type,
        public readonly bool $isStatic,
        public readonly bool $isReadonly,
        public readonly bool $promoted,
        public readonly bool $hasDefault,
        public readonly string $visibility,
        public readonly array $hooks,
    ) {
    }

    public function hasHook(string $kind): bool
    {
        return isset($this->hooks[$kind]);
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }
}

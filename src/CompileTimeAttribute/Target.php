<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

final class Target
{
    public function __construct(
        public readonly string $fqcn,
        public readonly string $shortName,
        public readonly string $kind,
        public readonly ?string $name,
    ) {
    }

    public function isClass(): bool
    {
        return $this->kind === 'class';
    }

    public function isTrait(): bool
    {
        return $this->kind === 'trait';
    }

    public function isEnum(): bool
    {
        return $this->kind === 'enum';
    }

    public function isProperty(): bool
    {
        return $this->kind === 'property';
    }

    public function isMethod(): bool
    {
        return $this->kind === 'method';
    }
}

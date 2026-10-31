<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

final class ClassInfo
{
    /** @param list<string> $implements */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $shortName,
        public readonly bool $isFinal,
        public readonly bool $isAbstract,
        public readonly bool $isReadonly,
        public readonly ?string $extends,
        public readonly array $implements,
        public readonly bool $isEnum,
        public readonly ?string $enumBackingType,
    ) {
    }
}

<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

final class ParamInfo
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $type,
        public readonly bool $byRef,
        public readonly bool $variadic,
        public readonly bool $hasDefault,
        public readonly bool $defaultIsLiteral,
        public readonly bool $promoted,
    ) {
    }
}

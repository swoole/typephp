<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

final class MethodSignature
{
    /** @param list<ParamInfo> $params */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly ?string $returnType,
        public readonly bool $isStatic,
        public readonly bool $isAbstract,
        public readonly bool $isFinal,
        public readonly bool $isPublic,
        public readonly bool $isProtected,
        public readonly bool $isPrivate,
        public readonly bool $returnsByRef,
    ) {
    }

    public function paramNames(): array
    {
        return array_map(static fn (ParamInfo $param): string => $param->name, $this->params);
    }

    public function isConstructor(): bool
    {
        return strcasecmp($this->name, '__construct') === 0;
    }
}

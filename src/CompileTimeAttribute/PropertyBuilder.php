<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

final class PropertyBuilder
{
    public function __construct(
        private readonly string $property,
        private readonly ProductBuffer $buffer,
    ) {
    }

    public function setter(string $body): void
    {
        $this->buffer->addHook($this->property, 'set', $body);
    }

    public function getter(string $expression): void
    {
        $this->buffer->addHook($this->property, 'get', $expression);
    }
}

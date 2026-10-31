<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

use TypePhp\Diagnostics\CompileTimeAttributeDiagnostic;

final class Site
{
    public function __construct(
        public readonly string $attribute,
        public readonly string $target,
        public readonly string $file,
        public readonly int $line,
    ) {
    }

    public function describe(): string
    {
        return '#[' . $this->attribute . '] on ' . $this->target . ' at ' . $this->file . ':' . $this->line;
    }

    public function format(string $message): string
    {
        return CompileTimeAttributeDiagnostic::formatPositions(
            $message,
            $this->attribute,
            $this->target,
            $this->file,
            $this->line,
        );
    }
}

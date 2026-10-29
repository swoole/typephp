<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

use Attribute;

/**
 * Declares that a provider class handles one compile-time annotation.
 *
 * `target` is the only source of truth for the host shape. The annotation class
 * is never reflected: a reflected bitmask would be a second source of truth and
 * PHP has already renumbered those bits between releases.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ProviderFor
{
    /** @param class-string $attribute */
    public function __construct(
        public readonly string $attribute,
        public readonly string $target,
    ) {
    }
}

<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

/**
 * Raised by a provider and re-reported at the attribute usage site.
 *
 * The message stays raw so Expansion appends `[compile-time attribute: ...]`
 * exactly once; the marker check keeps a message that already carries it from
 * being annotated twice.
 */
final class ProviderException extends \RuntimeException
{
    public const FORMAT_MARKER = '[compile-time attribute:';

    public function isFormatted(): bool
    {
        return str_contains($this->getMessage(), self::FORMAT_MARKER);
    }
}

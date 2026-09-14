<?php
/**
 * This file is part of TypePHP(AOT).
 *
 * @link     https://www.swoole.com/aot/
 * @contact  service@swoole.com
 */

namespace NamespacedBuiltinFallback;

class Probe
{
    private array $blocks;
    private int $size = 0;

    public function __construct(array $blocks)
    {
        $this->blocks = $blocks;
    }

    public function countBlocks(): int
    {
        $this->size = count($this->blocks);
        return $this->size;
    }

    public function matches(): bool
    {
        return in_array('needle', $this->blocks, true)
            && str_contains('needle in haystack', 'needle');
    }
}

function strlen(string $value): int
{
    return 7;
}

function shadowedLength(): int
{
    return strlen('ignored');
}

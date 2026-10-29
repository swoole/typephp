<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\CompileTimeAttribute;

final class ProductBuffer
{
    /** @var list<string> */
    private array $members = [];

    /** @var array<string, array<string, list<string>>> */
    private array $hooks = [];

    public function addMember(string $source): void
    {
        $this->members[] = $source;
    }

    public function addHook(string $property, string $channel, string $body): void
    {
        $this->hooks[$property][$channel][] = $body;
    }

    /** @return list<string> */
    public function members(): array
    {
        return $this->members;
    }

    /** @return array<string, array<string, list<string>>> */
    public function hooks(): array
    {
        return $this->hooks;
    }
}

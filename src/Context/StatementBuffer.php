<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Context;

/** Statements emitted around the expression currently being lowered. */
final class StatementBuffer
{
    /** @var list<string> */
    private array $before = [];
    /** @var list<string> */
    private array $after = [];

    public function addBefore(string $line): void
    {
        $this->before[] = $line;
    }

    public function addAfter(string $line): void
    {
        $this->after[] = $line;
    }

    public function hasBefore(): bool
    {
        return $this->before !== [];
    }

    public function hasPending(): bool
    {
        return $this->before !== [] || $this->after !== [];
    }

    /** @return list<string> */
    public function peekBefore(): array
    {
        return $this->before;
    }

    public function afterCount(): int
    {
        return count($this->after);
    }

    /** @param list<string> $lines */
    public function insertAfter(int $offset, array $lines): void
    {
        if ($offset < 0 || $offset > count($this->after)) {
            throw new \OutOfBoundsException('Invalid statement cleanup insertion offset');
        }
        array_splice($this->after, $offset, 0, $lines);
    }

    /** @return list<string> */
    public function takeBefore(): array
    {
        $lines = $this->before;
        $this->before = [];
        return $lines;
    }

    /** @return list<string> */
    public function takeAfter(): array
    {
        $lines = $this->after;
        $this->after = [];
        return $lines;
    }

    public function clear(): void
    {
        $this->before = [];
        $this->after = [];
    }

    /**
     * Capture only newly emitted statements, leaving the enclosing buffer
     * intact even if lowering throws. Nested captures own separate suffixes.
     *
     * @return array{mixed, list<string>, list<string>}
     */
    public function capture(callable $lower): array
    {
        $before = $this->before;
        $after = $this->after;
        try {
            $value = $lower();
            return [$value, array_slice($this->before, count($before)), array_slice($this->after, count($after))];
        } finally {
            $this->before = $before;
            $this->after = $after;
        }
    }
}

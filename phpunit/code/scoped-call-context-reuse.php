<?php

namespace ScopedCallContext;

class ScopedCallContextReuse
{
    private function mapValue(int $value): int
    {
        return $value * 2;
    }

    public function run(array $rows): void
    {
        foreach ($rows as $row) {
            array_map([$this, 'mapValue'], $row);
            array_filter($row, [$this, 'mapValue']);
            preg_replace_callback_array([
                '/[0-9]+/' => [self::class, 'replaceDigits'],
            ], 'item-123');
        }

        $callback = self::mapValue(...);
        $callback(1);
    }

    private static function replaceDigits(array $matches): string
    {
        return '[' . $matches[0] . ']';
    }
}

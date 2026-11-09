--TEST--
Chained factory receiver falls back to __call() for an inaccessible declared method
--FILE--
<?php

class ChainMagicFactory
{
    public static function make(): self
    {
        return new self();
    }

    private function value(): string
    {
        return 'private';
    }

    public function __call(string $name, array $args): mixed
    {
        return $name . ':' . implode(',', $args);
    }
}

function main(): void
{
    echo ChainMagicFactory::make()->value('a', 'b'), "\n";
}

?>
--EXPECT--
value:a,b

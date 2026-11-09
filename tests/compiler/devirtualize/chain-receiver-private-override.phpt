--TEST--
Chained factory receiver dispatches a child public method over a declared private method
--FILE--
<?php

class ChainPrivateBaseFactory
{
    public static function make(): self
    {
        return new ChainPrivateChildFactory();
    }

    private function value(): string
    {
        return 'base';
    }
}

final class ChainPrivateChildFactory extends ChainPrivateBaseFactory
{
    public function value(string $suffix = ''): string
    {
        return 'child' . $suffix;
    }
}

function main(): void
{
    echo ChainPrivateBaseFactory::make()->value('!'), "\n";
    $factory = ChainPrivateBaseFactory::make();
    echo $factory->value('?'), "\n";
}

?>
--EXPECT--
child!
child?

<?php
final class PropertyCounter
{
    public function value(): int { return 7; }
}
final class PropertyCounterHolder
{
    public PropertyCounter $counter;
    public function __construct() { $this->counter = new PropertyCounter(); }
    public function run(int $count): int
    {
        $sum = 0;
        for ($i = 0; $i < $count; ++$i) {
            $sum += $this->counter->value();
        }
        return $sum;
    }
}
function main(int $argc, array $argv): void
{
    echo (new PropertyCounterHolder())->run(1000000), "\n";
}

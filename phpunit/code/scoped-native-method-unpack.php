<?php

final class ScopedNativeTarget
{
    public function combine(int $a, int $b = 2): int
    {
        return $a + $b;
    }
}

final class ScopedNativeCaller
{
    private function local(int $a, int $b): int
    {
        return $a * $b;
    }

    public function run(array $args): int
    {
        $target = new ScopedNativeTarget();
        return $target->combine(...$args) + $this->local(...$args);
    }
}

function main(): void
{
    var_dump((new ScopedNativeCaller())->run([3, 4]));
}

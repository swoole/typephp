<?php

trait LateResolvedTrait
{
    public function traitCall(string $name): string { return static::label($name); }
}

class LateResolvedBase
{
    use LateResolvedTrait;

    protected static function label(string $name = ''): string { return static::class . $name; }
    protected function value(int $n = 1): int { return $n; }
    protected static function mutate(int &$n): void { $n++; }

    public function zero(): int { return static::value(); }
    public function withArgs(int $n): int { return static::value(lateCallArgument($n)); }
    public function dynamic(string $name, int $n): int { return static::{$name}(lateCallArgument($n)); }
    public function expression(array $names, int $n): int { return static::{$names[0]}(lateCallArgument($n)); }
    public static function fromStatic(string $name): string { return static::label($name); }
    public function named(): int { return static::value(n: 7); }
    public function spread(array $args): int { return static::value(...$args); }
    public function reference(int &$n): void { static::mutate($n); }
    public function closure(): int {
        $call = function (): int { return static::value(8); };
        return $call();
    }
    public function staticClosure(): int {
        $call = static function (): int { return static::value(); };
        return $call();
    }
}

class LateResolvedChild extends LateResolvedBase
{
    protected function value(int $n = 1): int { return $n + 1; }
}

function lateCallArgument(int $n): int { return $n; }

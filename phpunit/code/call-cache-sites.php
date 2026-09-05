<?php

function call_cache_target(int $value): int
{
    return $value + 1;
}

function call_cache_sites(mixed $callback, object $object, ?object $nullable, mixed $method): array
{
    return [
        $callback(1),
        $object->$method(2),
        $object->fixedMethod(3),
        $nullable?->nullableMethod(4),
    ];
}

function call_cache_known_internal_method(ArrayObject $object): int
{
    return $object->count();
}

class CallCacheStaticTarget
{
    public static function fixedMethod(int $value): int
    {
        return $value + 1;
    }
}

function call_cache_static_sites(mixed $class, mixed $method): array
{
    return [
        $class::$method(5),
        CallCacheStaticTarget::$method(6),
        $class::fixedMethod(7),
    ];
}

function call_cache_stable_object_static_method(mixed $method): int
{
    $object = new CallCacheStaticTarget();

    return $object::$method(8);
}

class CallCacheScopedTarget
{
    private function hidden(): int
    {
        return 3;
    }

    public function invoke(mixed $method): int
    {
        return $this->$method();
    }
}

function main(): void
{
}

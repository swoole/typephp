<?php

namespace InstanceOfMethodFixture;

interface Marker {}
class Base {}
final class Child extends Base implements Marker
{
    public function value(): int { return 7; }
}

function typed(Child $object): bool { return $object->instanceOf(Base::class); }
function generic(object $object, string $class): bool { return $object->instanceOf($class); }
function dynamic(mixed $object, string $class): bool { return $object->instanceOf($class); }
function nullable(?Child $object): bool { return $object->instanceOf(Marker::class); }
function nullsafe(?Child $object, string $class): ?bool { return $object?->instanceOf($class); }
function named(Child $object): bool { return $object->INSTANCEOF(class: Child::class); }
function immutable(#[\Immutable] Child $object): bool { return $object->instanceOf(Marker::class); }
function make(): Child { return new Child(); }
function chained(): bool { return make()->instanceOf(Child::class); }
function narrowed(mixed $object): int
{
    if ($object->instanceOf(Child::class)) {
        return $object->value();
    }
    return 0;
}

#[\Native]
final class NativeValue {}
function native(?NativeValue $object): bool { return $object->instanceOf(NativeValue::class); }
function nativeOther(NativeValue $object): bool { return $object->instanceOf(\stdClass::class); }

class Holder
{
    public ?Child $object = null;
    public function check(): bool { return $this->object->instanceOf(Child::class); }
}

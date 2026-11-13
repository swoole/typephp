--TEST--
Object instanceOf keyword matches instanceof for typed, generic, nullable and dynamic receivers
--FILE--
<?php
namespace ObjectInstanceOfMethod {
    interface Marker {}
    class Base {}
    final class Subject extends Base implements Marker {
        public function checkSelf(): bool { return $this->instanceOf(self::class); }
        public function checkParent(): bool { return $this->instanceOf(parent::class); }
        public function value(): int { return 7; }
    }
    class Holder {
        public ?Subject $object = null;
        public function check(): bool { return $this->object->instanceOf(Subject::class); }
    }
    function typed(Subject $object, string $class): bool { return $object->instanceOf($class); }
    function generic(object $object, string $class): bool { return $object->instanceOf($class); }
    function dynamic(mixed $object, string $class): bool { return $object->instanceOf($class); }
    function nullable(?Subject $object): bool { return $object->instanceOf(Subject::class); }
    function immutable(#[\Immutable] Subject $object): bool { return $object->instanceOf(Marker::class); }
    function narrowed(mixed $object): int {
        if ($object->instanceOf(Subject::class)) { return $object->value(); }
        return 0;
    }
    function make(): object { echo "receiver\n"; return new Subject(); }
    function target(): string { echo "target\n"; return Subject::class; }
    function replace(mixed &$object): string { $object = new \stdClass(); return Subject::class; }
    function snapshot(mixed $object): void {
        var_dump($object->instanceOf(replace($object)));
        var_dump($object instanceof \stdClass);
    }
    function nullsafe(?Subject $object): ?bool { return $object?->instanceOf(target()); }
}
namespace {
    function main(): void {
        $object = new \ObjectInstanceOfMethod\Subject();
        var_dump(\ObjectInstanceOfMethod\typed($object, \ObjectInstanceOfMethod\Base::class));
        var_dump(\ObjectInstanceOfMethod\generic($object, \ObjectInstanceOfMethod\Marker::class));
        var_dump(\ObjectInstanceOfMethod\generic(new \stdClass(), \ObjectInstanceOfMethod\Subject::class));
        var_dump(\ObjectInstanceOfMethod\dynamic($object, \ObjectInstanceOfMethod\Subject::class));
        var_dump(\ObjectInstanceOfMethod\dynamic(null, \ObjectInstanceOfMethod\Subject::class));
        var_dump(\ObjectInstanceOfMethod\dynamic(42, \ObjectInstanceOfMethod\Subject::class));
        var_dump(\ObjectInstanceOfMethod\nullable(null));
        var_dump($object->instanceOf(class: \ObjectInstanceOfMethod\Marker::class));
        var_dump($object->INSTANCEOF(\ObjectInstanceOfMethod\Subject::class));
        var_dump($object->checkSelf(), $object->checkParent());
        var_dump(\ObjectInstanceOfMethod\immutable($object));
        var_dump(\ObjectInstanceOfMethod\narrowed(std::any($object)));
        $holder = new \ObjectInstanceOfMethod\Holder();
        var_dump($holder->check());
        $holder->object = $object;
        var_dump($holder->check());
        var_dump(\ObjectInstanceOfMethod\make()->instanceOf(\ObjectInstanceOfMethod\target()));
        \ObjectInstanceOfMethod\snapshot(std::any($object));
        var_dump(\ObjectInstanceOfMethod\nullsafe(null));
        var_dump(\ObjectInstanceOfMethod\nullsafe($object));
        var_dump($object->instanceOf('MissingInstanceOfKeywordTarget'));
    }
}
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(true)
bool(false)
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(7)
bool(false)
bool(true)
receiver
target
bool(true)
bool(true)
bool(true)
NULL
target
bool(true)
bool(false)

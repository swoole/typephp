<?php
namespace LibraryLifecycle;

interface Handler
{
    public function act(): void;
}

class Base
{
    public int $count = 0;
    protected function hook(): void {}
    public function run(): void { $this->hook(); }
    public function getCount(): int { return $this->count; }
}

class NonEmptyBase
{
    public int $count = 0;
    protected function hook(): void { $this->count += 100; }
    public function run(): void { $this->hook(); }
    public function getCount(): int { return $this->count; }
}

// Overrides must belong to the library's compilation, so its method bodies
// see the complete hierarchy before choosing native or dynamic dispatch.
class Derived extends Base
{
    protected function hook(): void { $this->count += 1; }
}

class NonEmptyDerived extends NonEmptyBase
{
    protected function hook(): void { $this->count += 1; }
}

function dispatch(Handler $handler): void
{
    $handler->act();
}

function nextCount(): int
{
    static $count = 7;
    return ++$count;
}

function share(): void
{
    global $libraryShared;
    $libraryShared = 'ready';
}

--TEST--
Inherited method dispatch uses the unique implementation for the receiver's possible classes
--FILE--
<?php

class DispatchBase
{
    public function value(): int { return 1; }
    private function hidden(): int { return 8; }
    public function lexical(): int { return $this->hidden(); }
    final public function fixed(): int { return 9; }
}

abstract class DispatchMid extends DispatchBase
{
    public function current(): int { return $this->value(); }
}

class DispatchOverride extends DispatchMid
{
    public function value(): int { return 2; }
    public function hidden(): int { return 3; }
}

class DispatchInherited extends DispatchMid {}

class DispatchBranch extends DispatchBase
{
    public function current(): int { return $this->value(); }
}

class DispatchBranchLeaf extends DispatchBranch {}
final class DispatchFinal extends DispatchBase {}

abstract class DispatchAbstract
{
    abstract public function value(int $step = 1): int;
    public function current(): int { return $this->value(); }
}

class DispatchConcrete extends DispatchAbstract
{
    public function value(int $step = 7): int { return $step; }
}

function dispatchBase(DispatchBase $object): int { return $object->value(); }
function dispatchMid(DispatchMid $object): int { return $object->value(); }
function dispatchLeaf(DispatchInherited $object): int { return $object->value(); }
function dispatchBranch(DispatchBranch $object): int { return $object->value(); }
function dispatchFinal(DispatchFinal $object): int { return $object->value(); }
function dispatchAbstract(DispatchAbstract $object): int { return $object->value(); }
function dispatchFactory(): DispatchMid { return new DispatchOverride(); }
function dispatchReassigned(bool $replace): int
{
    $object = new DispatchBase();
    if ($replace) {
        $object = new DispatchOverride();
    }
    return $object->value();
}

function main(): void
{
    $overridden = new DispatchOverride();
    $inherited = new DispatchInherited();
    $branch = new DispatchBranchLeaf();
    $concrete = new DispatchConcrete();
    var_dump($overridden->current(), $inherited->current());
    var_dump(dispatchBase($overridden), dispatchBase($inherited));
    var_dump(dispatchMid($overridden), dispatchMid($inherited));
    var_dump(dispatchLeaf($inherited), dispatchBranch($branch), $branch->current());
    var_dump(dispatchFinal(new DispatchFinal()), $overridden->fixed());
    var_dump($overridden->lexical(), $overridden->hidden());
    var_dump($concrete->current(), dispatchAbstract($concrete));
    var_dump((new DispatchBase())->value(), dispatchFactory()->value());
    var_dump(dispatchReassigned(false), dispatchReassigned(true));
}
?>
--EXPECT--
int(2)
int(1)
int(2)
int(1)
int(2)
int(1)
int(1)
int(1)
int(1)
int(1)
int(9)
int(8)
int(3)
int(7)
int(7)
int(1)
int(2)
int(1)
int(2)

<?php

namespace DispatchProof;

class Root
{
    public function value(): int { return 1; }
    final public function fixed(): int { return 9; }
    private function hidden(): int { return 8; }
    public function lexical(): int { return $this->hidden(); }
}

class Mid extends Root
{
    public function current(): int { return $this->value(); }
}

class OverrideLeaf extends Mid
{
    public function value(): int { return 2; }
    public function hidden(): int { return 3; }
}

class InheritedLeaf extends Mid {}

class Branch extends Root
{
    public function current(): int { return $this->value(); }
}

class BranchLeaf extends Branch {}
final class FinalReceiver extends Root {}

abstract class AbstractRoot
{
    abstract public function value(int $step = 1): int;
    public function current(): int { return $this->value(); }
}

class ConcreteReceiver extends AbstractRoot
{
    public function value(int $step = 7): int { return $step; }
}

function rootParameter(Root $object): int { return $object->value(); }
function midParameter(Mid $object): int { return $object->value(); }
function leafParameter(InheritedLeaf $object): int { return $object->value(); }
function branchParameter(Branch $object): int { return $object->value(); }
function finalParameter(FinalReceiver $object): int { return $object->value(); }
function finalMethod(Root $object): int { return $object->fixed(); }
function exactLocal(): int { $object = new Root(); return $object->value(); }
function exactTemporary(): int { return (new Root())->value(); }
function makeMid(): Mid { return new OverrideLeaf(); }
function returnedBound(): int { return makeMid()->value(); }
function abstractParameter(AbstractRoot $object): int { return $object->value(); }
function replacedLocal(bool $replace): int
{
    $object = new Root();
    if ($replace) {
        $object = new OverrideLeaf();
    }
    return $object->value();
}

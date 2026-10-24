--TEST--
Trait abstract requirements resolve self and parent in the composition scope
--FILE--
<?php
class LateBoundBase {}

trait LateBoundRequirement {
    abstract public function selfValue(self|null $value): self|null;
    abstract public function parentValue(parent|null $value): parent|null;
}

class LateBoundConsumer extends LateBoundBase {
    use LateBoundRequirement;

    public function selfValue(LateBoundConsumer|null $value): LateBoundConsumer|null {
        return $value;
    }

    public function parentValue(LateBoundBase|null $value): LateBoundBase|null {
        return $value;
    }
}

trait NestedSelfRequirement {
    abstract public function copy(self $value): self;
}

trait NestedSelfImplementation {
    public function copy(self $value): self {
        return $value;
    }
}

trait NestedSelfComposition {
    use NestedSelfRequirement, NestedSelfImplementation;
}

class NestedSelfConsumer {
    use NestedSelfComposition;
}

trait NestedParentMethod {
    public function parentFromNestedTrait(): parent {
        return new LateBoundBase();
    }
}

trait NestedParentMethodComposition {
    use NestedParentMethod;
}

class NestedParentMethodConsumer extends LateBoundBase {
    use NestedParentMethodComposition;
}

trait NestedParentRequirement {
    abstract public function nestedParent(parent $value): parent;
}

trait NestedParentRequirementComposition {
    use NestedParentRequirement;
}

class NestedParentRequirementConsumer extends LateBoundBase {
    use NestedParentRequirementComposition;

    public function nestedParent(LateBoundBase $value): LateBoundBase {
        return $value;
    }
}

trait DeferredRequirement {
    abstract public function deferred(self|null $value): self|null;
}

abstract class DeferredBase {
    use DeferredRequirement;
}

class DeferredImplementation extends DeferredBase {
    public function deferred(DeferredBase|null $value): DeferredImplementation|null {
        return $this;
    }
}

function main(): void {
    $consumer = new LateBoundConsumer();
    var_dump($consumer->selfValue($consumer) === $consumer);

    $base = new LateBoundBase();
    var_dump($consumer->parentValue($base) === $base);

    $nested = new NestedSelfConsumer();
    var_dump($nested->copy($nested) === $nested);

    $nestedParentMethod = new NestedParentMethodConsumer();
    var_dump($nestedParentMethod->parentFromNestedTrait() instanceof LateBoundBase);

    $nestedParentRequirement = new NestedParentRequirementConsumer();
    var_dump($nestedParentRequirement->nestedParent($base) === $base);

    $deferred = new DeferredImplementation();
    var_dump($deferred->deferred(null) === $deferred);

    var_dump((string) (new ReflectionMethod(NestedSelfConsumer::class, 'copy'))->getReturnType());
    var_dump((string) (new ReflectionMethod(NestedParentMethodConsumer::class, 'parentFromNestedTrait'))->getReturnType());
    var_dump((string) (new ReflectionMethod(DeferredBase::class, 'deferred'))->getReturnType());
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(18) "NestedSelfConsumer"
string(13) "LateBoundBase"
string(13) "?DeferredBase"

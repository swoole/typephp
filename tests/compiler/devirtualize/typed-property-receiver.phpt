--TEST--
Typed property method calls preserve references, receiver order, overrides, hooks and uninitialized access
--FILE--
<?php

final class PropertyCallTarget
{
    public function __construct(public int $id = 1) {}

    public function record(array &$events, int $value = 0): int
    {
        $events[] = $this->id + $value;
        return $this->id;
    }
}

class PropertyCallBase
{
    public function value(): int { return 1; }
}

final class PropertyCallChild extends PropertyCallBase
{
    public function value(): int { return 2; }
}

final class PropertyCallHolder
{
    public PropertyCallTarget $target;
    public PropertyCallBase $polymorphic;
    public ?PropertyCallTarget $nullable = null;
    public PropertyCallTarget $uninitialized;
    public int $reads = 0;
    public PropertyCallTarget $hooked {
        get {
            ++$this->reads;
            return $this->target;
        }
    }

    public function __construct()
    {
        $this->target = new PropertyCallTarget();
        $this->polymorphic = new PropertyCallChild();
    }

    public function replace(): int
    {
        $this->target = new PropertyCallTarget(9);
        return 3;
    }

    public function checkHook(array &$events): array
    {
        $id = $this->hooked->record($events);
        return [$id, $this->reads];
    }

    public function checkUninitialized(array &$events): string
    {
        try {
            $this->uninitialized->record($events);
        } catch (Error $error) {
            return 'uninitialized';
        }
        return 'unexpected';
    }

    public function run(array &$events): array
    {
        $first = $this->target->record($events, $this->replace());
        $second = $this->target->record(value: 2, events: $events);
        return [$first, $second, $this->polymorphic->value(), $this->nullable?->record($events)];
    }
}

function main(): void
{
    $holder = new PropertyCallHolder();
    $events = [];
    $result = $holder->run($events);
    $hook = $holder->checkHook($events);
    echo json_encode([$result, $events, $holder->checkUninitialized($events), $hook], JSON_THROW_ON_ERROR), "\n";
}

?>
--EXPECT--
[[1,9,2,null],[4,11,9],"uninitialized",[9,1]]

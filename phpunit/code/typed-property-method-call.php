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

class PrivateMethodPropertyBase
{
    private function value(): string
    {
        return 'base';
    }
}

final class PrivateMethodPropertyChild extends PrivateMethodPropertyBase
{
    public function value(): string
    {
        return 'child';
    }
}

final class PrivateMethodPropertyHolder
{
    public PrivateMethodPropertyBase $target;

    public function run(): string
    {
        return $this->target->value();
    }
}

interface InterfacePropertyRecorder
{
    public function record(array &$events): void;
}

final class InterfacePropertyRecorderImpl implements InterfacePropertyRecorder
{
    public function record(array &$events): void
    {
        $events[] = 'recorded';
    }
}

final class InterfacePropertyHolder
{
    public InterfacePropertyRecorder $target;

    public function run(array &$events): void
    {
        $this->target->record($events);
    }
}

final class StaticPropertyCallTarget
{
    public function record(array &$events): void
    {
        $events[] = 'recorded';
    }
}

final class StaticPropertyCallHolder
{
    public static StaticPropertyCallTarget $target;

    public static function run(array &$events): void
    {
        self::$target->record($events);
    }
}

function unrelatedRecordSignature(array &$events): void
{
}

function dynamicReceiverRecordCall(mixed $target, array $events): void
{
    $target->unrelatedRecordSignature($events);
}

final class MissingSignaturePropertyTarget
{
}

function missingSignaturePropertyMethod(array &$events): void
{
}

final class MissingSignaturePropertyHolder
{
    public MissingSignaturePropertyTarget $target;

    public function run(array $events): void
    {
        $this->target->missingSignaturePropertyMethod($events);
    }
}

class LexicalPrivatePropertyBase
{
    public LexicalPrivateFinalPropertyChild $finalTarget;
    public LexicalPrivateOpenPropertyChild $openTarget;

    private function record(array &$events): void
    {
        $events[] = 'base';
    }

    public function runFinal(array &$events): void
    {
        $this->finalTarget->record($events);
    }

    public function runOpen(array &$events): void
    {
        $this->openTarget->record($events);
    }
}

final class LexicalPrivateFinalPropertyChild extends LexicalPrivatePropertyBase
{
    public function record(array $events): void
    {
    }
}

class LexicalPrivateOpenPropertyChild extends LexicalPrivatePropertyBase
{
    public function record(array $events): void
    {
    }
}

final class MagicPrivatePropertyTarget
{
    private function hidden(array &$events): void
    {
    }

    public function __call(string $name, array $arguments): void
    {
    }
}

final class MagicPrivatePropertyHolder
{
    public MagicPrivatePropertyTarget $target;

    public function run(array $events): void
    {
        $this->target->hidden($events);
    }
}

final class MagicMissingPropertyTarget
{
    public function __call(string $name, array $arguments): void
    {
    }
}

final class MagicMissingPropertyHolder
{
    public MagicMissingPropertyTarget $target;

    public function run(array $events): void
    {
        $this->target->missing($events);
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

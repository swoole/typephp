--TEST--
Interface typed property receiver preserves by-reference arguments
--FILE--
<?php

interface TypedPropertyEventRecorder
{
    public function record(array &$events): void;
}

final class TypedPropertyEventRecorderImpl implements TypedPropertyEventRecorder
{
    public function record(array &$events): void
    {
        $events[] = 'recorded';
    }
}

final class TypedPropertyInterfaceHolder
{
    public TypedPropertyEventRecorder $target;

    public function __construct()
    {
        $this->target = new TypedPropertyEventRecorderImpl();
    }

    public function run(array &$events): void
    {
        $this->target->record($events);
    }
}

function main(): void
{
    $events = [];
    $holder = new TypedPropertyInterfaceHolder();
    $holder->run($events);
    echo json_encode($events, JSON_THROW_ON_ERROR), "\n";
}

?>
--EXPECT--
["recorded"]

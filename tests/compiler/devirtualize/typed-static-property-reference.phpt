--TEST--
Non-nullable typed static property receiver preserves by-reference arguments
--FILE--
<?php

final class TypedStaticPropertyTarget
{
    public function record(array &$events): void
    {
        $events[] = 'recorded';
    }
}

final class TypedStaticPropertyHolder
{
    public static TypedStaticPropertyTarget $target;

    public static function run(array &$events): void
    {
        self::$target->record($events);
    }
}

function main(): void
{
    TypedStaticPropertyHolder::$target = new TypedStaticPropertyTarget();
    $events = [];
    TypedStaticPropertyHolder::run($events);
    echo json_encode($events, JSON_THROW_ON_ERROR), "\n";
}

?>
--EXPECT--
["recorded"]

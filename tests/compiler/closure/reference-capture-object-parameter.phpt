--TEST--
Object parameter reference capture changes local storage while preserving the entry type contract
--FILE--
<?php
final class ReferenceCaptureInput
{
}

function capture_object_parameter(ReferenceCaptureInput $value): Closure
{
    return static function () use (&$value): mixed {
        $value = 17;
        return $value;
    };
}

function main(): void
{
    $callback = capture_object_parameter(new ReferenceCaptureInput());
    var_dump($callback());

    try {
        capture_object_parameter(std::any('invalid'));
        echo "unexpected\n";
    } catch (TypeError $error) {
        echo "TypeError\n";
    }
}
?>
--EXPECT--
int(17)
TypeError
